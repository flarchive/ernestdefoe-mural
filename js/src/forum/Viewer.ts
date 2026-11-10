import { paletteWords } from './api';

/**
 * The canvas engine, without Mithril: one <canvas> on screen, one buffer
 * canvas the size of the mural (a pixel per tile). Drawing is a single
 * drawImage of the buffer through a transform, so it costs the same at
 * any zoom and on any phone; a placed tile is one putImageData of the
 * changed rectangle. Draws only when something changed (requestAnimationFrame).
 *
 * Pan: drag (with a fling). Zoom: wheel or trackpad pinch at the pointer,
 * two-finger pinch, double click/tap, keys. A tap or click selects a tile.
 */

export type Tile = [number, number];
type Rect = [number, number, number, number];

export interface ViewerHooks {
  onSelect?(tile: Tile | null): void;
  onHover?(tile: Tile | null, clientX: number, clientY: number): void;
  onView?(): void;
  onArea?(rect: Rect): void;
  onConfirm?(): void;
}

const MAX_SCALE = 64;
const TAP_SLOP = 7;
const FLASH_MS = 900;

export default class Viewer {
  readonly canvas: HTMLCanvasElement;
  private ctx: CanvasRenderingContext2D;
  private buffer = document.createElement('canvas');
  private bctx = this.buffer.getContext('2d')!;
  private image: ImageData | null = null;
  private words: Uint32Array | null = null;
  private colours = new Uint32Array(0);
  pixels = new Uint8Array(0);
  width = 0;
  height = 0;

  scale = 1;
  ox = 0;
  oy = 0;
  private cw = 0;
  private ch = 0;
  private dpr = 1;

  selected: Tile | null = null;
  preview: string | null = null;
  areaMode = false;
  area: Rect | null = null;

  private dirty: Rect | null = null;
  private frame = 0;
  private anim: { from: [number, number, number]; to: [number, number, number]; start: number; ms: number } | null = null;
  private fling: { vx: number; vy: number; last: number } | null = null;
  private flashes: { x: number; y: number; at: number }[] = [];

  private pointers = new Map<number, { x: number; y: number }>();
  private gesture: any = null;
  private lastTap = 0;
  private hoverTile = '';
  private observer: ResizeObserver;
  private fitted = false;

  constructor(
    canvas: HTMLCanvasElement,
    private hooks: ViewerHooks = {}
  ) {
    this.canvas = canvas;
    this.ctx = canvas.getContext('2d', { alpha: true })!;
    canvas.addEventListener('pointerdown', this.down);
    canvas.addEventListener('pointermove', this.move);
    canvas.addEventListener('pointerup', this.up);
    canvas.addEventListener('pointercancel', this.up);
    canvas.addEventListener('pointerleave', this.leave);
    canvas.addEventListener('wheel', this.wheel, { passive: false });
    canvas.addEventListener('keydown', this.key);
    canvas.addEventListener('contextmenu', (e) => e.preventDefault());
    this.observer = new ResizeObserver(() => this.resize());
    this.observer.observe(canvas.parentElement || canvas);
    this.resize();
  }

  destroy(): void {
    this.observer.disconnect();
    cancelAnimationFrame(this.frame);
    this.canvas.removeEventListener('wheel', this.wheel);
  }

  // ── the picture ─────────────────────────────────────────────

  setImage(width: number, height: number, palette: string[], pixels: Uint8Array): void {
    const sizeChanged = width !== this.width || height !== this.height;
    this.width = width;
    this.height = height;
    this.colours = paletteWords(palette);
    this.pixels = pixels.length === width * height ? pixels : new Uint8Array(width * height);
    this.buffer.width = width;
    this.buffer.height = height;
    this.image = this.bctx.createImageData(width, height);
    this.words = new Uint32Array(this.image.data.buffer);
    for (let i = 0; i < this.pixels.length; i++) this.words[i] = this.colours[this.pixels[i]] ?? this.colours[0];
    this.dirty = [0, 0, width, height];
    if (sizeChanged || !this.fitted) this.fit(false);
    this.draw();
  }

  colourAt(x: number, y: number): number {
    return this.pixels[y * this.width + x] ?? 0;
  }

  setPixel(x: number, y: number, c: number, flash = false): void {
    if (!this.words || x < 0 || y < 0 || x >= this.width || y >= this.height) return;
    const i = y * this.width + x;
    this.pixels[i] = c;
    this.words[i] = this.colours[c] ?? this.colours[0];
    const d = this.dirty;
    this.dirty = d ? [Math.min(d[0], x), Math.min(d[1], y), Math.max(d[2], x + 1), Math.max(d[3], y + 1)] : [x, y, x + 1, y + 1];
    if (flash && this.scale >= 3) this.flashes.push({ x, y, at: performance.now() });
    this.draw();
  }

  /** Flat [x, y, c, x, y, c, …], applied in order. */
  setPixels(flat: number[], flash = false): void {
    for (let i = 0; i + 2 < flat.length; i += 3) this.setPixel(flat[i], flat[i + 1], flat[i + 2], flash && flat.length <= 60);
  }

  /** Many changes at once (the timelapse): write straight into the words, then mark everything dirty. */
  bulk(write: (pixels: Uint8Array, words: Uint32Array, colours: Uint32Array) => void): void {
    if (!this.words) return;
    write(this.pixels, this.words, this.colours);
    this.dirty = [0, 0, this.width, this.height];
    this.draw();
  }

  // ── the view ────────────────────────────────────────────────

  private fitScale(): number {
    if (!this.width || !this.cw) return 1;
    return Math.min((this.cw - 24) / this.width, (this.ch - 24) / this.height);
  }

  private minScale(): number {
    return Math.max(0.1, this.fitScale() * 0.5);
  }

  fit(animate = true): void {
    if (!this.width || !this.cw) return;
    this.fitted = true;
    const s = this.fitScale();
    this.go([s, (this.cw - this.width * s) / 2, (this.ch - this.height * s) / 2], animate);
  }

  /** Put tile (x, y) in the middle at this zoom. */
  centerOn(x: number, y: number, scale = Math.max(this.scale, 16), animate = true): void {
    const s = this.clampScale(scale);
    this.go([s, this.cw / 2 - (x + 0.5) * s, this.ch / 2 - (y + 0.5) * s], animate);
  }

  zoomBy(factor: number, cx = this.cw / 2, cy = this.ch / 2, animate = true): void {
    const base = this.anim ? this.anim.to : ([this.scale, this.ox, this.oy] as [number, number, number]);
    const s = this.clampScale(base[0] * factor);
    const k = s / base[0];
    this.go([s, cx - (cx - base[1]) * k, cy - (cy - base[2]) * k], animate);
  }

  private clampScale(s: number): number {
    return Math.max(this.minScale(), Math.min(MAX_SCALE, s));
  }

  /** Keep some of the mural on screen, whatever the pan. */
  private clampOffsets(): void {
    const keep = 48;
    const w = this.width * this.scale;
    const h = this.height * this.scale;
    this.ox = Math.min(this.cw - keep, Math.max(keep - w, this.ox));
    this.oy = Math.min(this.ch - keep, Math.max(keep - h, this.oy));
  }

  private go(to: [number, number, number], animate: boolean): void {
    this.fling = null;
    const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    if (animate && !reduce) {
      this.anim = { from: [this.scale, this.ox, this.oy], to, start: performance.now(), ms: 220 };
    } else {
      this.anim = null;
      [this.scale, this.ox, this.oy] = to;
      this.clampOffsets();
      this.hooks.onView?.();
    }
    this.draw();
  }

  /** The tile under a point in CSS pixels relative to the canvas, or null off the mural. */
  tileAt(px: number, py: number): Tile | null {
    const x = Math.floor((px - this.ox) / this.scale);
    const y = Math.floor((py - this.oy) / this.scale);
    return x >= 0 && y >= 0 && x < this.width && y < this.height ? [x, y] : null;
  }

  /** Where a tile's top-left corner is on screen (CSS px, relative to the canvas). */
  screenOf(x: number, y: number): { x: number; y: number; size: number } {
    return { x: this.ox + x * this.scale, y: this.oy + y * this.scale, size: this.scale };
  }

  /** The tile in the middle of the view. */
  centre(): Tile {
    return [
      Math.max(0, Math.min(this.width - 1, Math.floor((this.cw / 2 - this.ox) / this.scale))),
      Math.max(0, Math.min(this.height - 1, Math.floor((this.ch / 2 - this.oy) / this.scale))),
    ];
  }

  select(tile: Tile | null, notify = true): void {
    this.selected = tile;
    if (notify) this.hooks.onSelect?.(tile);
    this.draw();
  }

  resize(): void {
    const box = this.canvas.getBoundingClientRect();
    if (!box.width || !box.height) return;
    const [cx, cy] = [this.cw / 2, this.ch / 2];
    const first = !this.cw;
    this.dpr = Math.min(3, window.devicePixelRatio || 1);
    this.cw = box.width;
    this.ch = box.height;
    this.canvas.width = Math.round(box.width * this.dpr);
    this.canvas.height = Math.round(box.height * this.dpr);
    if (first || !this.fitted) {
      this.fit(false);
    } else {
      // Keep what was in the middle in the middle.
      this.ox += this.cw / 2 - cx;
      this.oy += this.ch / 2 - cy;
      this.clampOffsets();
    }
    this.draw(true);
  }

  // ── drawing ─────────────────────────────────────────────────

  draw(now = false): void {
    if (now) {
      cancelAnimationFrame(this.frame);
      this.frame = 0;
      this.render(performance.now());
    } else if (!this.frame) {
      this.frame = requestAnimationFrame((t) => {
        this.frame = 0;
        this.render(t);
      });
    }
  }

  private render(time: number): void {
    let again = false;

    if (this.anim) {
      const a = this.anim;
      const p = Math.min(1, (time - a.start) / a.ms);
      const e = 1 - Math.pow(1 - p, 3);
      // Zoom in log space so it feels even.
      this.scale = Math.exp(Math.log(a.from[0]) + (Math.log(a.to[0]) - Math.log(a.from[0])) * e);
      // Offsets follow the scale's progress, so the point being zoomed on stays put.
      const k = a.to[0] === a.from[0] ? e : (this.scale - a.from[0]) / (a.to[0] - a.from[0]);
      this.ox = a.from[1] + (a.to[1] - a.from[1]) * k;
      this.oy = a.from[2] + (a.to[2] - a.from[2]) * k;
      if (p >= 1) {
        this.anim = null;
        this.clampOffsets();
      }
      this.hooks.onView?.();
      again = !!this.anim;
    } else if (this.fling) {
      const f = this.fling;
      const dt = Math.min(48, time - f.last);
      f.last = time;
      this.ox += f.vx * dt;
      this.oy += f.vy * dt;
      const decay = Math.pow(0.992, dt);
      f.vx *= decay;
      f.vy *= decay;
      this.clampOffsets();
      if (Math.hypot(f.vx, f.vy) < 0.02) this.fling = null;
      this.hooks.onView?.();
      again = !!this.fling;
    }

    if (this.dirty && this.image) {
      const [x0, y0, x1, y1] = this.dirty;
      this.bctx.putImageData(this.image, 0, 0, x0, y0, x1 - x0, y1 - y0);
      this.dirty = null;
    }

    const ctx = this.ctx;
    const d = this.dpr;
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
    if (!this.width) return;

    const s = this.scale * d;
    const ox = this.ox * d;
    const oy = this.oy * d;

    // The mural's edge: a soft shadow so a white canvas still reads as a canvas.
    ctx.save();
    ctx.shadowColor = 'rgba(0,0,0,0.25)';
    ctx.shadowBlur = 18 * d;
    ctx.fillStyle = '#fff';
    ctx.fillRect(ox, oy, this.width * s, this.height * s);
    ctx.restore();

    ctx.imageSmoothingEnabled = false;
    ctx.setTransform(s, 0, 0, s, ox, oy);
    ctx.drawImage(this.buffer, 0, 0);
    ctx.setTransform(1, 0, 0, 1, 0, 0);

    // Visible tile range, for the grid.
    const vx0 = Math.max(0, Math.floor(-ox / s));
    const vy0 = Math.max(0, Math.floor(-oy / s));
    const vx1 = Math.min(this.width, Math.ceil((this.canvas.width - ox) / s));
    const vy1 = Math.min(this.height, Math.ceil((this.canvas.height - oy) / s));

    if (s >= 10) {
      ctx.beginPath();
      for (let x = vx0; x <= vx1; x++) {
        const px = Math.round(ox + x * s) + 0.5;
        ctx.moveTo(px, oy + vy0 * s);
        ctx.lineTo(px, oy + vy1 * s);
      }
      for (let y = vy0; y <= vy1; y++) {
        const py = Math.round(oy + y * s) + 0.5;
        ctx.moveTo(ox + vx0 * s, py);
        ctx.lineTo(ox + vx1 * s, py);
      }
      ctx.lineWidth = 1;
      ctx.strokeStyle = `rgba(0,0,0,${Math.min(0.12, (s - 10) / 120 + 0.04)})`;
      ctx.stroke();
    }

    // New tiles from others: a ring that grows and fades.
    if (this.flashes.length) {
      this.flashes = this.flashes.filter((f) => time - f.at < FLASH_MS);
      for (const f of this.flashes) {
        const p = (time - f.at) / FLASH_MS;
        const cx = ox + (f.x + 0.5) * s;
        const cy = oy + (f.y + 0.5) * s;
        ctx.beginPath();
        ctx.arc(cx, cy, s * (0.6 + p * 1.6), 0, Math.PI * 2);
        ctx.lineWidth = 2 * d;
        ctx.strokeStyle = `rgba(255,255,255,${0.9 * (1 - p)})`;
        ctx.stroke();
        ctx.lineWidth = 1 * d;
        ctx.strokeStyle = `rgba(0,0,0,${0.5 * (1 - p)})`;
        ctx.stroke();
      }
      again = again || this.flashes.length > 0;
    }

    if (this.area) {
      const [x1, y1, x2, y2] = this.area;
      const rx = ox + x1 * s;
      const ry = oy + y1 * s;
      const rw = (x2 - x1 + 1) * s;
      const rh = (y2 - y1 + 1) * s;
      ctx.fillStyle = 'rgba(215, 38, 61, 0.18)';
      ctx.fillRect(rx, ry, rw, rh);
      ctx.setLineDash([6 * d, 4 * d]);
      ctx.lineWidth = 2 * d;
      ctx.strokeStyle = '#d7263d';
      ctx.strokeRect(rx, ry, rw, rh);
      ctx.setLineDash([]);
    }

    if (this.selected) {
      const [x, y] = this.selected;
      const rx = ox + x * s;
      const ry = oy + y * s;
      if (this.preview) {
        ctx.globalAlpha = 0.9;
        ctx.fillStyle = this.preview;
        ctx.fillRect(rx, ry, s, s);
        ctx.globalAlpha = 1;
      }
      // A reticle that reads on any colour: dark outline, light inner line, corner ticks when large.
      const pad = Math.max(2 * d, s * 0.12);
      ctx.lineWidth = 3 * d;
      ctx.strokeStyle = 'rgba(0,0,0,0.85)';
      ctx.strokeRect(rx - pad, ry - pad, s + pad * 2, s + pad * 2);
      ctx.lineWidth = 1.5 * d;
      ctx.strokeStyle = '#fff';
      ctx.strokeRect(rx - pad, ry - pad, s + pad * 2, s + pad * 2);
    }

    if (again) this.draw();
  }

  // ── input ───────────────────────────────────────────────────

  private local(e: { clientX: number; clientY: number }) {
    const box = this.canvas.getBoundingClientRect();
    return { x: e.clientX - box.left, y: e.clientY - box.top };
  }

  private down = (e: PointerEvent) => {
    if (e.button !== 0 && e.pointerType === 'mouse') return;
    this.canvas.setPointerCapture?.(e.pointerId);
    const p = this.local(e);
    this.pointers.set(e.pointerId, p);
    this.anim = null;
    this.fling = null;

    if (this.pointers.size === 1) {
      this.gesture = {
        kind: 'pan',
        start: p,
        last: p,
        t0: performance.now(),
        moved: false,
        ox0: this.ox,
        oy0: this.oy,
        vx: 0,
        vy: 0,
        lt: performance.now(),
        areaFrom: this.areaMode ? this.tileAt(p.x, p.y) : null,
      };
    } else if (this.pointers.size === 2) {
      const [a, b] = [...this.pointers.values()];
      const mid = { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
      this.gesture = {
        kind: 'pinch',
        dist: Math.hypot(a.x - b.x, a.y - b.y) || 1,
        mid,
        scale0: this.scale,
        tx: (mid.x - this.ox) / this.scale,
        ty: (mid.y - this.oy) / this.scale,
      };
    }
  };

  private move = (e: PointerEvent) => {
    const p = this.local(e);

    if (!this.pointers.has(e.pointerId)) {
      // Hover (mouse or pen, no button).
      if (e.pointerType !== 'touch') {
        const tile = this.tileAt(p.x, p.y);
        const key = tile ? tile.join() : '';
        if (key !== this.hoverTile) {
          this.hoverTile = key;
          this.hooks.onHover?.(tile, e.clientX, e.clientY);
        }
      }
      return;
    }
    this.pointers.set(e.pointerId, p);
    const g = this.gesture;
    if (!g) return;

    if (g.kind === 'pinch' && this.pointers.size >= 2) {
      const [a, b] = [...this.pointers.values()];
      const mid = { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
      this.scale = this.clampScale((g.scale0 * Math.hypot(a.x - b.x, a.y - b.y)) / g.dist);
      this.ox = mid.x - g.tx * this.scale;
      this.oy = mid.y - g.ty * this.scale;
      this.clampOffsets();
      this.hooks.onView?.();
      this.draw();
      return;
    }

    if (g.kind === 'pan') {
      if (!g.moved && Math.hypot(p.x - g.start.x, p.y - g.start.y) > TAP_SLOP) g.moved = true;
      if (!g.moved) return;
      if (g.areaFrom) {
        const to = this.tileAt(Math.max(0, p.x), Math.max(0, p.y)) || g.areaFrom;
        this.area = [Math.min(g.areaFrom[0], to[0]), Math.min(g.areaFrom[1], to[1]), Math.max(g.areaFrom[0], to[0]), Math.max(g.areaFrom[1], to[1])];
        this.draw();
        return;
      }
      const t = performance.now();
      const dt = Math.max(1, t - g.lt);
      g.vx = 0.8 * ((p.x - g.last.x) / dt) + 0.2 * g.vx;
      g.vy = 0.8 * ((p.y - g.last.y) / dt) + 0.2 * g.vy;
      g.last = p;
      g.lt = t;
      this.ox = g.ox0 + (p.x - g.start.x);
      this.oy = g.oy0 + (p.y - g.start.y);
      this.clampOffsets();
      this.hooks.onView?.();
      this.draw();
    }
  };

  private up = (e: PointerEvent) => {
    if (!this.pointers.has(e.pointerId)) return;
    this.pointers.delete(e.pointerId);
    const g = this.gesture;

    if (this.pointers.size === 1 && g?.kind === 'pinch') {
      // One finger left after a pinch: carry on as a pan from here, never a tap.
      const p = [...this.pointers.values()][0];
      this.gesture = {
        kind: 'pan',
        start: p,
        last: p,
        t0: 0,
        moved: true,
        ox0: this.ox,
        oy0: this.oy,
        vx: 0,
        vy: 0,
        lt: performance.now(),
        areaFrom: null,
      };
      return;
    }
    if (this.pointers.size > 0) return;
    this.gesture = null;
    if (!g || g.kind !== 'pan') return;

    if (g.areaFrom) {
      if (!g.moved) this.area = [g.areaFrom[0], g.areaFrom[1], g.areaFrom[0], g.areaFrom[1]];
      if (this.area) this.hooks.onArea?.(this.area);
      return;
    }

    if (g.moved) {
      if (performance.now() - g.lt < 80 && Math.hypot(g.vx, g.vy) > 0.25) {
        this.fling = { vx: g.vx, vy: g.vy, last: performance.now() };
        this.draw();
      }
      return;
    }

    // A tap: select. Two quick taps on the same spot: zoom in there.
    const p = this.local(e);
    const now = performance.now();
    if (now - this.lastTap < 300) {
      this.lastTap = 0;
      this.zoomBy(2.5, p.x, p.y);
      return;
    }
    this.lastTap = now;
    this.select(this.tileAt(p.x, p.y));
    if (e.pointerType === 'mouse') this.canvas.focus({ preventScroll: true });
  };

  private leave = (e: PointerEvent) => {
    if (e.pointerType === 'touch' || this.pointers.has(e.pointerId)) return;
    this.hoverTile = '';
    this.hooks.onHover?.(null, 0, 0);
  };

  private wheel = (e: WheelEvent) => {
    e.preventDefault();
    const p = this.local(e);
    // Trackpad pinch arrives as a wheel with ctrlKey and small deltas.
    const delta = e.deltaMode === 1 ? e.deltaY * 16 : e.deltaY;
    const factor = Math.exp(-delta * (e.ctrlKey ? 0.01 : 0.002));
    const s = this.clampScale(this.scale * factor);
    const k = s / this.scale;
    this.anim = null;
    this.fling = null;
    this.ox = p.x - (p.x - this.ox) * k;
    this.oy = p.y - (p.y - this.oy) * k;
    this.scale = s;
    this.clampOffsets();
    this.hooks.onView?.();
    this.draw();
  };

  private key = (e: KeyboardEvent) => {
    const moves: Record<string, Tile> = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };
    if (moves[e.key]) {
      e.preventDefault();
      const [dx, dy] = moves[e.key];
      const from = this.selected || this.centre();
      const to: Tile = [Math.max(0, Math.min(this.width - 1, from[0] + dx)), Math.max(0, Math.min(this.height - 1, from[1] + dy))];
      this.select(to);
      const at = this.screenOf(to[0], to[1]);
      if (at.x < 0 || at.y < 0 || at.x + at.size > this.cw || at.y + at.size > this.ch) this.centerOn(to[0], to[1], this.scale);
    } else if (e.key === '+' || e.key === '=') {
      this.zoomBy(1.5);
    } else if (e.key === '-' || e.key === '_') {
      this.zoomBy(1 / 1.5);
    } else if (e.key === 'Enter' && this.selected) {
      e.preventDefault();
      this.hooks.onConfirm?.();
    } else if (e.key === 'Escape') {
      this.select(null);
    }
  };
}
