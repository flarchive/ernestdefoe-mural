import app from 'flarum/forum/app';
import { call, canInflate, inflate, now, setSkew, t, type Me, type State } from './api';
import type Viewer from './Viewer';

/**
 * Keeps an open canvas true to the server.
 *
 * The picture loads once; after that only what changed travels. With
 * flarum/realtime connected, each tile arrives the moment it is placed and
 * a slow poll is just a safety net; without it, a poll every few seconds
 * while the tab is visible. The poll asks "anything after placement N?",
 * which a quiet canvas answers from the cache without a query. The cursor
 * only moves with what the server says (a pushed tile is drawn at once but
 * also comes back with the next poll, in order), so a lost push costs
 * nothing.
 */

const POLL = 4000;
const SAFETY = 25000;

export default class Live {
  state: State | null = null;
  error = false;
  me: Me | null = null;
  private upto = 0;
  private timer: number | undefined;
  private channel: any = null;
  private stopped = false;
  private polling = false;

  constructor(
    private viewer: () => Viewer | null,
    private season?: number
  ) {
    document.addEventListener('visibilitychange', this.visible);
  }

  stop(): void {
    this.stopped = true;
    clearTimeout(this.timer);
    document.removeEventListener('visibilitychange', this.visible);
    this.channel?.unbind?.('mural', this.onPush);
  }

  async load(): Promise<void> {
    clearTimeout(this.timer);
    const params: Record<string, string> = {};
    if (this.season) params.season = String(this.season);
    if (!canInflate()) params.raw = '1';

    try {
      const res = await app.request<{ data: State; now: string }>({
        method: 'GET',
        url: app.forum.attribute<string>('apiUrl') + '/mural' + (Object.keys(params).length ? '?' + new URLSearchParams(params) : ''),
        background: true,
      });
      setSkew(res.now);
      const state = res.data;
      const pixels = await inflate(state.snapshot, !!state.raw);
      if (this.stopped) return;
      this.state = state;
      this.me = state.me || null;
      this.upto = state.upto;
      this.error = false;
      const viewer = this.viewer();
      if (viewer) {
        viewer.setImage(state.season.width, state.season.height, state.season.palette, pixels);
        viewer.setPixels(state.tail);
      }
    } catch {
      this.error = !this.state;
    }
    m.redraw();
    this.schedule();
  }

  /** Our own tile, drawn before the server answers; put back if it says no. */
  async place(x: number, y: number, colour: number): Promise<boolean> {
    const viewer = this.viewer();
    if (!viewer || !this.me) return false;
    const before = viewer.colourAt(x, y);
    viewer.setPixel(x, y, colour);
    try {
      const res = await call<{ id: number; nextAt: string; now: string }>('POST', '/tiles', { x, y, color: colour }, true);
      setSkew(res.now);
      this.me.nextAt = res.nextAt;
      this.me.tiles++;
      return true;
    } catch (error: any) {
      viewer.setPixel(x, y, before);
      const err = error?.response?.errors?.[0];
      if (err?.code === 'mural_cooldown' && err.meta?.nextAt) this.me.nextAt = err.meta.nextAt;
      if (err?.code === 'mural_season_over') this.load();
      if (err?.code === 'mural_banned') this.me.banned = { until: err.meta?.until ?? null, reason: null };
      throw error;
    } finally {
      m.redraw();
    }
  }

  secondsLeft(): number {
    const next = this.me?.nextAt ? Date.parse(this.me.nextAt) : 0;
    return Math.max(0, Math.ceil((next - now()) / 1000));
  }

  // ── freshness ─────────────────────────────────────────────

  private live(): boolean {
    return !!this.state?.live && !this.stopped;
  }

  private realtime(): boolean {
    // The server pushes only when the mural is public and a queue carries it.
    if (!this.state?.push) return false;
    const socket = (app as any).websocket;
    if (!socket || typeof socket.subscribe !== 'function') return false;
    // Realtime subscribes only guests to "public"; members join it themselves (no auth needed).
    const channel = socket.subscribe('public');
    if (channel && channel !== this.channel) {
      this.channel?.unbind?.('mural', this.onPush);
      channel.bind('mural', this.onPush);
      this.channel = channel;
    }
    return socket.connection?.state === 'connected';
  }

  private schedule(delay?: number): void {
    clearTimeout(this.timer);
    if (!this.live() || document.hidden) return;
    const wait = delay ?? (this.realtime() ? SAFETY : POLL);
    this.timer = window.setTimeout(() => this.poll(), wait);
  }

  private visible = () => {
    if (!document.hidden && this.live()) this.poll();
  };

  async poll(): Promise<void> {
    if (!this.state || this.polling) return;
    this.polling = true;
    let soon = false;
    try {
      const res = await call<{ reset?: boolean; upto: number; p: number[]; more?: boolean }>(
        'GET',
        '/changes',
        { s: String(this.state.season.id), since: String(this.upto), epoch: String(this.state.epoch) },
        true
      );
      if (res.reset) {
        const ended = this.state.season;
        await this.load();
        if (this.state && this.state.season.id !== ended.id) {
          app.alerts.show({ type: 'success' }, t('alerts.new_season', { number: this.state.season.number }));
        }
        return;
      }
      if (res.p.length) this.viewer()?.setPixels(res.p, true);
      if (res.p.length && this.state) this.state.season.tiles += res.p.length / 3;
      this.upto = Math.max(this.upto, res.upto);
      soon = !!res.more;
      if (res.p.length) m.redraw();
    } catch {
      // Offline for a moment: try again on the next beat.
    } finally {
      this.polling = false;
    }
    this.schedule(soon ? 250 : undefined);
  }

  private onPush = (data: any) => {
    if (!this.state || !data) return;
    if (data.s !== this.state.season.id || data.reset || data.e !== this.state.epoch) {
      this.poll();
      return;
    }
    if (Array.isArray(data.p) && data.p[0] > this.upto) {
      const [, x, y, c] = data.p;
      this.viewer()?.setPixel(x, y, c, true);
    }
  };
}
