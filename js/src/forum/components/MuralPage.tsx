import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import LinkButton from 'flarum/common/components/LinkButton';
import Dropdown from 'flarum/common/components/Dropdown';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import classList from 'flarum/common/utils/classList';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { call, errorText, fmt, seasonName, t, type StatsRow, type TileInfo } from '../api';
import Live from '../Live';
import Viewer, { type Tile } from '../Viewer';
import { Hours, HoverCard, Leaders, type Hover } from './parts';
import Dock from './Dock';
import { BansModal, EndSeasonModal, RollbackModal } from './ModTools';
import { IndexSidebar, PageStructure } from '../paint';

/** /mural: the canvas everyone paints, the dock to paint with, and how it's going. */
export default class MuralPage extends Page {
  live!: Live;
  viewer: Viewer | null = null;
  stats: StatsRow | null = null;
  colour: number | null = null;
  selected: Tile | null = null;
  info: TileInfo | null | undefined = undefined;
  hover: Hover | null = null;
  placing = false;
  zoomLabel = '';
  private infoCache = new Map<string, TileInfo | null>();
  private hoverTimer: number | undefined;
  private statsTimer: number | undefined;
  private tick: number | undefined;
  private urlTimer: number | undefined;
  private dockObserver: ResizeObserver | undefined;
  private stageObserver: IntersectionObserver | undefined;
  /** Phones: the dock is fixed to the bottom while the canvas is on screen, and in its slot otherwise. */
  pinned = false;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    app.history.push('mural', extractText(t('page.title')));
    app.setTitle(extractText(t('page.title')));
    app.setTitleCount(0);
    this.bodyClass = 'App--mural';
    this.live = new Live(() => this.viewer);
    this.loadStats();
    this.statsTimer = window.setInterval(() => !document.hidden && this.loadStats(), 60000);
    // The cooldown ring counts down by the second; nothing else redraws on a timer.
    this.tick = window.setInterval(() => this.live.me?.nextAt && m.redraw(), 1000);
  }

  oncreate(vnode: Mithril.VnodeDOM) {
    super.oncreate(vnode);
    const canvas = vnode.dom.querySelector('.MuralStage-canvas') as HTMLCanvasElement;
    this.viewer = new Viewer(canvas, {
      onSelect: (tile) => this.choose(tile),
      onHover: (tile, x, y) => this.hovered(tile, x, y),
      onView: () => this.viewChanged(),
      onArea: (rect) => this.areaChosen(rect),
      onConfirm: () => this.place(),
    });
    this.live.load().then(() => this.fromUrl());
    this.liftDock();
    window.addEventListener('resize', this.liftDock);
    this.dockObserver = new ResizeObserver(() => this.liftDock());
    const dock = vnode.dom.querySelector('.MuralDock');
    if (dock) this.dockObserver.observe(dock);
    this.stageObserver = new IntersectionObserver(([entry]) => {
      if (entry.isIntersecting !== this.pinned) {
        this.pinned = entry.isIntersecting;
        m.redraw();
      }
    });
    this.stageObserver.observe(canvas);
  }

  /**
   * On phones the dock is fixed to the bottom of the screen (sticky would do,
   * but any theme with overflow-x: hidden on <body> silently turns sticky off).
   * It sits above a bottom tab bar (Mobile Tab and its forks), and the page
   * keeps room for it at the end.
   */
  liftDock = () => {
    const bar = document.querySelector<HTMLElement>('.MobileTab-bar, .MobileTab');
    const lift = bar && getComputedStyle(bar).position === 'fixed' && bar.offsetHeight ? bar.offsetHeight : 0;
    const dock = document.querySelector<HTMLElement>('.MuralDock');
    const root = document.documentElement.style;
    root.setProperty('--mural-dock-bottom', `${lift}px`);
    root.setProperty('--mural-dock-height', `${dock?.offsetHeight || 0}px`);
  };

  onremove(vnode: Mithril.VnodeDOM) {
    super.onremove(vnode);
    this.live.stop();
    this.viewer?.destroy();
    window.removeEventListener('resize', this.liftDock);
    this.dockObserver?.disconnect();
    this.stageObserver?.disconnect();
    document.documentElement.style.removeProperty('--mural-dock-bottom');
    document.documentElement.style.removeProperty('--mural-dock-height');
    clearInterval(this.statsTimer);
    clearInterval(this.tick);
    clearTimeout(this.hoverTimer);
    clearTimeout(this.urlTimer);
  }

  loadStats() {
    call<{ data: StatsRow }>('GET', '/stats', undefined, true)
      .then((res) => (this.stats = res.data))
      .catch(() => {})
      .finally(() => m.redraw());
  }

  // ── the canvas talking back ─────────────────────────────────

  /** A shared link (?x=&y=&z=) opens on that spot. */
  fromUrl() {
    const q = new URLSearchParams(window.location.search);
    const x = parseInt(q.get('x') || '', 10);
    const y = parseInt(q.get('y') || '', 10);
    const v = this.viewer;
    if (!v || isNaN(x) || isNaN(y) || x < 0 || y < 0 || x >= v.width || y >= v.height) return;
    v.centerOn(x, y, parseFloat(q.get('z') || '') || 20, false);
    v.select([x, y]);
  }

  choose(tile: Tile | null) {
    this.selected = tile;
    this.info = undefined;
    if (tile) this.lookup(tile).then((info) => this.selected === tile && ((this.info = info), m.redraw()));
    this.syncUrl();
    m.redraw();
  }

  hovered(tile: Tile | null, x: number, y: number) {
    clearTimeout(this.hoverTimer);
    if (!tile) {
      if (this.hover) ((this.hover = null), m.redraw());
      return;
    }
    // Ask only once the pointer rests on a tile.
    this.hover = null;
    this.hoverTimer = window.setTimeout(() => {
      this.hover = { tile, x, y };
      m.redraw();
      this.lookup(tile).then((info) => {
        if (this.hover?.tile === tile) {
          this.hover.info = info;
          m.redraw();
        }
      });
    }, 280);
  }

  lookup(tile: Tile): Promise<TileInfo | null> {
    const season = this.live.state?.season;
    if (!season) return Promise.resolve(null);
    const key = `${season.id}:${tile.join()}:${this.viewer?.colourAt(tile[0], tile[1])}`;
    if (this.infoCache.has(key)) return Promise.resolve(this.infoCache.get(key)!);
    return call<{ data: TileInfo | null }>('GET', '/tiles', { x: String(tile[0]), y: String(tile[1]) }, true)
      .then((res) => {
        if (this.infoCache.size > 400) this.infoCache.clear();
        this.infoCache.set(key, res.data);
        return res.data;
      })
      .catch(() => null);
  }

  viewChanged() {
    const v = this.viewer;
    if (!v) return;
    const label = v.scale >= 1 ? `${Math.round(v.scale * 10) / 10}×` : `${Math.round(v.scale * 100)}%`;
    if (label !== this.zoomLabel) {
      this.zoomLabel = label;
      m.redraw();
    }
    if (this.hover) ((this.hover = null), m.redraw());
    this.syncUrl();
  }

  /** Keep the address shareable without a route change (no redraw, no history entry). */
  syncUrl() {
    clearTimeout(this.urlTimer);
    this.urlTimer = window.setTimeout(() => {
      const v = this.viewer;
      if (!v || !v.width) return;
      const [x, y] = this.selected || v.centre();
      const url = new URL(window.location.href);
      url.searchParams.set('x', String(x));
      url.searchParams.set('y', String(y));
      url.searchParams.set('z', String(Math.round(v.scale * 10) / 10));
      window.history.replaceState(window.history.state, '', url.pathname + url.search);
    }, 400);
  }

  pickColour(index: number) {
    this.colour = index;
    if (this.viewer) {
      this.viewer.preview = this.live.state?.season.palette[index] || null;
      this.viewer.draw();
    }
  }

  async place() {
    const tile = this.selected;
    const me = this.live.me;
    if (!tile || this.colour === null || !me?.canPaint || this.live.secondsLeft() > 0 || this.placing) return;
    this.placing = true;
    m.redraw();
    try {
      await this.live.place(tile[0], tile[1], this.colour);
      this.infoCache.clear();
      this.info = undefined;
      if (this.viewer) this.viewer.preview = null;
      this.lookup(tile).then((info) => this.selected === tile && ((this.info = info), m.redraw()));
    } catch (error) {
      app.alerts.show({ type: 'error' }, errorText(error));
    } finally {
      this.placing = false;
      m.redraw();
    }
  }

  // ── moderation ──────────────────────────────────────────────

  startArea() {
    if (!this.viewer) return;
    this.viewer.areaMode = true;
    this.viewer.area = null;
    this.viewer.select(null);
    m.redraw();
  }

  stopArea() {
    if (!this.viewer) return;
    this.viewer.areaMode = false;
    this.viewer.area = null;
    this.viewer.draw();
    m.redraw();
  }

  areaChosen(rect: [number, number, number, number]) {
    if (this.viewer) this.viewer.areaMode = false;
    m.redraw();
    app.modal.show(RollbackModal, { rect, onclose: () => this.stopArea(), ondone: () => this.loadStats() });
  }

  // ── view ────────────────────────────────────────────────────

  view() {
    return (
      <PageStructure className="MuralPage" sidebar={() => <IndexSidebar />}>
        {this.head()}
        {this.stage()}
        <div className={classList('MuralDock-slot', this.pinned && 'is-pinned')}>
          <Dock page={this} />
        </div>
        {this.info_()}
      </PageStructure>
    );
  }

  head(): Mithril.Children {
    const state = this.live.state;
    const season = state?.season;

    return (
      <header className="MuralPage-head">
        <div className="MuralPage-titles">
          <h2 className="MuralPage-title">
            <Icon name="fas fa-palette" /> {t('page.title')}
          </h2>
          {season ? (
            <div className="MuralPage-meta">
              <span className="MuralPill MuralPill--live">
                <span className="MuralPill-dot" aria-hidden="true" />
                {t('page.live')}
              </span>
              <span className="MuralPage-season">{seasonName(season)}</span>
              {season.title ? <span className="MuralPage-number">{t('season.name', { number: season.number })}</span> : null}
              {this.stats?.active ? <span className="MuralPage-active">{t('page.active', { count: this.stats.active })}</span> : null}
            </div>
          ) : null}
        </div>
        <div className="MuralPage-actions">
          <LinkButton className="Button" icon="fas fa-images" href={app.route('mural.seasons')}>
            {t('gallery.link')}
          </LinkButton>
          {state?.canModerate ? (
            <Dropdown
              className="MuralPage-mod"
              buttonClassName="Button"
              label={t('mod.menu')}
              icon="fas fa-broom"
              menuClassName="Dropdown-menu--right"
            >
              <Button icon="fas fa-eraser" onclick={() => this.startArea()}>
                {t('mod.rollback')}
              </Button>
              <Button icon="fas fa-ban" onclick={() => app.modal.show(BansModal)}>
                {t('mod.bans')}
              </Button>
              <Button
                icon="fas fa-flag-checkered"
                onclick={() => app.modal.show(EndSeasonModal, { season: state.season, ondone: () => this.live.load() })}
              >
                {t('mod.end_season')}
              </Button>
            </Dropdown>
          ) : null}
        </div>
      </header>
    );
  }

  stage(): Mithril.Children {
    const v = this.viewer;
    const loading = !this.live.state && !this.live.error;
    const centre = this.selected || (v?.width ? v.centre() : null);

    return (
      <div className={classList('MuralStage', v?.areaMode && 'MuralStage--area')}>
        <canvas className="MuralStage-canvas" tabindex="0" aria-label={extractText(t('stage.label'))} />
        {loading ? <LoadingIndicator className="MuralStage-loading" /> : null}
        {this.live.error ? <p className="MuralStage-error">{t('stage.error')}</p> : null}
        {!loading && centre ? (
          <div className="MuralStage-chip MuralStage-coords" aria-hidden="true">
            {centre[0]}, {centre[1]} <span className="MuralStage-zoom">{this.zoomLabel}</span>
          </div>
        ) : null}
        <div className="MuralStage-tools">
          <Button
            className="Button Button--icon MuralStage-tool"
            icon="fas fa-plus"
            aria-label={extractText(t('stage.zoom_in'))}
            onclick={() => v?.zoomBy(1.6)}
          />
          <Button
            className="Button Button--icon MuralStage-tool"
            icon="fas fa-minus"
            aria-label={extractText(t('stage.zoom_out'))}
            onclick={() => v?.zoomBy(1 / 1.6)}
          />
          <Button
            className="Button Button--icon MuralStage-tool"
            icon="fas fa-expand"
            aria-label={extractText(t('stage.fit'))}
            onclick={() => v?.fit()}
          />
          <Button
            className="Button Button--icon MuralStage-tool"
            icon="fas fa-link"
            aria-label={extractText(t('stage.copy_link'))}
            onclick={() => this.copyLink()}
          />
        </div>
        {v?.areaMode || v?.area ? (
          <div className="MuralStage-banner">
            <Icon name="fas fa-eraser" /> {t('mod.area_help')}
            <Button className="Button Button--link" onclick={() => this.stopArea()}>
              {t('mod.cancel')}
            </Button>
          </div>
        ) : null}
        <HoverCard hover={this.hover} viewer={this.viewer} palette={this.live.state?.season.palette || []} />
      </div>
    );
  }

  copyLink() {
    this.syncUrl();
    window.setTimeout(() => {
      navigator.clipboard?.writeText(window.location.href).then(
        () => app.alerts.show({ type: 'success' }, t('stage.link_copied')),
        () => {}
      );
    }, 450);
  }

  info_(): Mithril.Children {
    const s = this.stats;
    const me = this.live.me;
    const season = this.live.state?.season;

    return (
      <section className="MuralInfo">
        <div className="MuralStats">
          <div className="MuralStat">
            <strong>{fmt(season?.tiles ?? s?.tiles ?? 0)}</strong>
            <span>{t('stats.tiles')}</span>
          </div>
          <div className="MuralStat">
            <strong>{fmt(s?.painters ?? season?.painters ?? 0)}</strong>
            <span>{t('stats.painters')}</span>
          </div>
          <div className="MuralStat">
            <strong>{fmt(s?.last24 ?? 0)}</strong>
            <span>{t('stats.last24')}</span>
          </div>
          {me ? (
            <div className="MuralStat MuralStat--mine">
              <strong>{me.rank ? `#${fmt(me.rank)}` : '—'}</strong>
              <span>{t('stats.mine', { count: me.tiles })}</span>
            </div>
          ) : (
            <div className="MuralStat">
              <strong>{season ? `${season.width}×${season.height}` : '—'}</strong>
              <span>{t('stats.size')}</span>
            </div>
          )}
        </div>
        <div className="MuralPanels">
          <section className="MuralPanel">
            <h3 className="MuralPanel-title">{t('stats.top')}</h3>
            {s ? <Leaders rows={s.top} /> : <LoadingIndicator size="small" display="block" />}
          </section>
          <section className="MuralPanel">
            <h3 className="MuralPanel-title">{t('stats.activity')}</h3>
            {s ? <Hours stats={s} /> : <LoadingIndicator size="small" display="block" />}
          </section>
          <section className="MuralPanel MuralPanel--how">
            <h3 className="MuralPanel-title">{t('how.title')}</h3>
            <ul className="MuralHow">
              <li>
                <Icon name="fas fa-hand-pointer" /> {t('how.pick')}
              </li>
              <li>
                <Icon name="fas fa-hourglass-half" /> {t('how.wait', { time: this.duration(me?.cooldown ?? null) })}
              </li>
              <li>
                <Icon name="fas fa-up-down-left-right" /> {t('how.move')}
              </li>
              <li>
                <Icon name="fas fa-images" /> {t('how.seasons')}
              </li>
            </ul>
          </section>
        </div>
      </section>
    );
  }

  duration(seconds: number | null): string {
    if (seconds === null) return extractText(t('how.a_while'));
    if (seconds < 60) return extractText(t('time.seconds', { count: seconds }));
    if (seconds < 3600) return extractText(t('time.minutes', { count: Math.round(seconds / 60) }));
    return extractText(t('time.hours', { count: Math.round((seconds / 3600) * 10) / 10 }));
  }
}
