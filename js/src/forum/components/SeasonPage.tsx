import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import LinkButton from 'flarum/common/components/LinkButton';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import classList from 'flarum/common/utils/classList';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { call, fmt, seasonName, t, type StatsRow, type TileInfo } from '../api';
import Live from '../Live';
import Viewer, { type Tile } from '../Viewer';
import { HoverCard, Leaders, type Hover } from './parts';
import { dateRange } from './GalleryPage';
import { IndexSidebar, PageStructure } from '../paint';

const SPEEDS = [0.5, 1, 2, 4];
/** A timelapse at 1× lasts about this long, whatever the season's size. */
const BASE_MS = 30000;

/**
 * /mural/seasons/{n}: a finished mural. The final picture (hover for who
 * painted what), its top painters, and the timelapse: every tile in the
 * order it was placed, read from one static file and drawn straight into
 * the same canvas engine.
 */
export default class SeasonPage extends Page {
  live!: Live;
  viewer: Viewer | null = null;
  stats: StatsRow | null = null;
  number = 0;
  // Timelapse
  data: DataView | null = null;
  total = 0;
  shown = 0;
  playing = false;
  speed = 1;
  fetching = false;
  hover: Hover | null = null;
  private frame = 0;
  private last = 0;
  private hoverTimer: number | undefined;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.number = parseInt(m.route.param('number'), 10) || 0;
    this.live = new Live(() => this.viewer, this.number);
    app.history.push('mural.season', extractText(t('season.name', { number: this.number })));
    app.setTitle(extractText(t('season.name', { number: this.number })));
    app.setTitleCount(0);
    call<{ data: StatsRow }>('GET', '/stats', { season: String(this.number) }, true)
      .then((res) => (this.stats = res.data))
      .catch(() => {})
      .finally(() => m.redraw());
  }

  oncreate(vnode: Mithril.VnodeDOM) {
    super.oncreate(vnode);
    this.viewer = new Viewer(vnode.dom.querySelector('.MuralStage-canvas') as HTMLCanvasElement, {
      onHover: (tile, x, y) => this.hovered(tile, x, y),
      onSelect: (tile) => tile && this.hovered(tile, 0, 0, true),
      onView: () => this.hover && ((this.hover = null), m.redraw()),
    });
    this.live.load().then(() => {
      const state = this.live.state;
      if (!state) return;
      const title = extractText(seasonName(state.season));
      app.setTitle(title);
    });
  }

  onremove(vnode: Mithril.VnodeDOM) {
    super.onremove(vnode);
    cancelAnimationFrame(this.frame);
    this.live.stop();
    this.viewer?.destroy();
    clearTimeout(this.hoverTimer);
  }

  hovered(tile: Tile | null, x: number, y: number, now = false) {
    clearTimeout(this.hoverTimer);
    // Who painted what is about the finished picture, not a frame of the timelapse.
    if (!tile || this.shown < this.total) {
      if (this.hover) ((this.hover = null), m.redraw());
      return;
    }
    const show = () => {
      const box = this.viewer!.canvas.getBoundingClientRect();
      const at = this.viewer!.screenOf(tile[0], tile[1]);
      this.hover = now ? { tile, x: box.left + at.x + at.size, y: box.top + at.y + at.size } : { tile, x, y };
      m.redraw();
      call<{ data: TileInfo | null }>('GET', '/tiles', { season: String(this.number), x: String(tile[0]), y: String(tile[1]) }, true)
        .then((res) => {
          if (this.hover?.tile === tile) {
            this.hover.info = res.data;
            m.redraw();
          }
        })
        .catch(() => {});
    };
    if (now) show();
    else this.hoverTimer = window.setTimeout(show, 280);
  }

  // ── timelapse ───────────────────────────────────────────────

  async fetchTimelapse(): Promise<boolean> {
    const url = this.live.state?.season.timelapseUrl;
    if (this.data) return true;
    if (!url) return false;
    this.fetching = true;
    m.redraw();
    try {
      const buffer = await (await fetch(url)).arrayBuffer();
      const view = new DataView(buffer);
      const magic = String.fromCharCode(view.getUint8(0), view.getUint8(1), view.getUint8(2), view.getUint8(3));
      if (magic !== 'MRL1') return false;
      this.data = view;
      this.total = Math.floor((buffer.byteLength - 8) / 5);
      this.shown = this.total;
      return true;
    } catch {
      return false;
    } finally {
      this.fetching = false;
      m.redraw();
    }
  }

  async play() {
    if (this.playing) return this.pause();
    if (!(await this.fetchTimelapse())) {
      app.alerts.show({ type: 'error' }, t('season.no_timelapse'));
      return;
    }
    if (this.shown >= this.total) this.seek(0);
    this.hover = null;
    this.playing = true;
    this.last = performance.now();
    const step = (time: number) => {
      if (!this.playing) return;
      const perMs = (this.total / BASE_MS) * this.speed;
      const target = Math.min(this.total, this.shown + Math.max(1, Math.round(perMs * Math.min(64, time - this.last))));
      this.last = time;
      this.advance(target);
      if (this.shown >= this.total) {
        this.playing = false;
      } else {
        this.frame = requestAnimationFrame(step);
      }
      m.redraw();
    };
    this.frame = requestAnimationFrame(step);
  }

  pause() {
    this.playing = false;
    cancelAnimationFrame(this.frame);
    m.redraw();
  }

  /** Draw placements up to `target` (forward only; seek() handles going back). */
  advance(target: number) {
    const view = this.data;
    const viewer = this.viewer;
    if (!view || !viewer) return;
    const from = this.shown;
    const width = viewer.width;
    viewer.bulk((pixels, words, colours) => {
      for (let i = from; i < target; i++) {
        const o = 8 + i * 5;
        const x = view.getUint16(o, true);
        const y = view.getUint16(o + 2, true);
        const c = view.getUint8(o + 4);
        const p = y * width + x;
        pixels[p] = c;
        words[p] = colours[c] ?? colours[0];
      }
    });
    this.shown = target;
  }

  seek(target: number) {
    const viewer = this.viewer;
    if (!viewer || !this.data) return;
    target = Math.max(0, Math.min(this.total, Math.round(target)));
    if (target < this.shown) {
      viewer.bulk((pixels, words, colours) => {
        pixels.fill(0);
        words.fill(colours[0]);
      });
      this.shown = 0;
    }
    this.advance(target);
    m.redraw();
  }

  // ── view ────────────────────────────────────────────────────

  view() {
    const state = this.live.state;
    const season = state?.season;

    return (
      <PageStructure className="MuralSeasonPage" sidebar={() => <IndexSidebar />}>
        <header className="MuralPage-head">
          <div className="MuralPage-titles">
            <h2 className="MuralPage-title">
              <Icon name="fas fa-image" /> {season ? seasonName(season) : t('season.name', { number: this.number })}
            </h2>
            {season ? (
              <div className="MuralPage-meta">
                {season.title ? <span>{t('season.name', { number: season.number })}</span> : null}
                <span>{dateRange(season)}</span>
                <span>{t('gallery.counts', { tiles: fmt(season.tiles), painters: fmt(season.painters) })}</span>
              </div>
            ) : null}
          </div>
          <div className="MuralPage-actions">
            <LinkButton className="Button" icon="fas fa-images" href={app.route('mural.seasons')}>
              {t('gallery.title')}
            </LinkButton>
            {season?.imageUrl ? (
              <a className="Button" href={season.imageUrl} download={`mural-season-${season.number}.png`}>
                <Icon name="fas fa-download" className="Button-icon" />
                <span className="Button-label">{t('season.download')}</span>
              </a>
            ) : null}
          </div>
        </header>
        <div className="MuralStage MuralStage--season">
          <canvas className="MuralStage-canvas" tabindex="0" aria-label={extractText(t('stage.label'))} />
          {!state && !this.live.error ? <LoadingIndicator className="MuralStage-loading" /> : null}
          {this.live.error ? <p className="MuralStage-error">{t('stage.error')}</p> : null}
          <div className="MuralStage-tools">
            <Button
              className="Button Button--icon MuralStage-tool"
              icon="fas fa-plus"
              aria-label={extractText(t('stage.zoom_in'))}
              onclick={() => this.viewer?.zoomBy(1.6)}
            />
            <Button
              className="Button Button--icon MuralStage-tool"
              icon="fas fa-minus"
              aria-label={extractText(t('stage.zoom_out'))}
              onclick={() => this.viewer?.zoomBy(1 / 1.6)}
            />
            <Button
              className="Button Button--icon MuralStage-tool"
              icon="fas fa-expand"
              aria-label={extractText(t('stage.fit'))}
              onclick={() => this.viewer?.fit()}
            />
          </div>
          <HoverCard hover={this.hover} viewer={this.viewer} palette={this.live.state?.season.palette || []} />
        </div>
        {season?.timelapseUrl ? this.player() : null}
        <section className="MuralInfo">
          <div className="MuralPanels">
            <section className="MuralPanel">
              <h3 className="MuralPanel-title">{t('stats.top')}</h3>
              {this.stats ? <Leaders rows={this.stats.top} /> : <LoadingIndicator size="small" display="block" />}
            </section>
          </div>
        </section>
      </PageStructure>
    );
  }

  player(): Mithril.Children {
    const pct = this.total ? (this.shown / this.total) * 100 : 100;

    return (
      <div className="MuralPlayer">
        <Button
          className="Button Button--primary MuralPlayer-play"
          icon={this.playing ? 'fas fa-pause' : 'fas fa-play'}
          loading={this.fetching}
          onclick={() => this.play()}
          aria-label={extractText(this.playing ? t('season.pause') : t('season.play'))}
        >
          {this.playing ? t('season.pause') : t('season.timelapse')}
        </Button>
        <input
          className="MuralPlayer-scrub"
          type="range"
          min="0"
          max={this.total || 1}
          value={this.total ? this.shown : 1}
          disabled={!this.data}
          aria-label={extractText(t('season.scrub'))}
          style={{ '--pct': `${pct}%` }}
          oninput={(e: InputEvent) => {
            this.pause();
            this.seek(parseInt((e.target as HTMLInputElement).value, 10));
          }}
        />
        <span className="MuralPlayer-count">{this.data ? `${fmt(this.shown)} / ${fmt(this.total)}` : ''}</span>
        <div className="MuralPlayer-speeds" role="group" aria-label={extractText(t('season.speed'))}>
          {SPEEDS.map((s) => (
            <button
              type="button"
              className={classList('MuralPlayer-speed', this.speed === s && 'is-active')}
              aria-pressed={this.speed === s ? 'true' : 'false'}
              onclick={() => (this.speed = s)}
            >
              {s}×
            </button>
          ))}
        </div>
      </div>
    );
  }
}
