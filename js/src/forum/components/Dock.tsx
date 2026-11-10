import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import classList from 'flarum/common/utils/classList';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { t } from '../api';
import { Person, Time } from './parts';
import type MuralPage from './MuralPage';

declare const flarum: any;

/**
 * Under the canvas: the tile you picked and who painted it, the palette,
 * your cooldown and the button that places. On phones it sticks to the
 * bottom of the screen, so painting never needs a scroll.
 */
export default class Dock extends Component<{ page: MuralPage }> {
  view(): Mithril.Children {
    const page = this.attrs.page;
    const state = page.live.state;
    if (!state) return <div className="MuralDock MuralDock--empty" />;

    return <div className="MuralDock">{app.session.user ? this.painter(page) : this.guest()}</div>;
  }

  guest(): Mithril.Children {
    return (
      <div className="MuralDock-note">
        <Icon name="fas fa-paintbrush" />
        <span>{t('dock.guest')}</span>
        <Button
          className="Button Button--primary"
          onclick={() => app.modal.show(() => flarum.reg.asyncModuleImport('flarum/forum/components/LogInModal'))}
        >
          {t('dock.log_in')}
        </Button>
      </div>
    );
  }

  painter(page: MuralPage): Mithril.Children {
    const me = page.live.me;
    if (!me) return null;
    if (me.banned) {
      return (
        <div className="MuralDock-note MuralDock-note--warn">
          <Icon name="fas fa-ban" />
          <span>{me.banned.until ? t('dock.banned_until', { date: new Date(me.banned.until).toLocaleString() }) : t('dock.banned')}</span>
        </div>
      );
    }
    if (!me.confirmed) {
      return (
        <div className="MuralDock-note">
          <Icon name="fas fa-envelope" />
          <span>{t('dock.confirm_email')}</span>
        </div>
      );
    }
    if (!me.canPaint) {
      return (
        <div className="MuralDock-note">
          <Icon name="fas fa-lock" />
          <span>{t('dock.no_permission')}</span>
        </div>
      );
    }

    const palette = page.live.state!.season.palette;
    const left = page.live.secondsLeft();
    const ready = left === 0;
    const canPlace = ready && page.selected && page.colour !== null && !page.placing;

    return [
      this.tileLine(page),
      <div className="MuralDock-row">
        <div className="MuralPalette" role="radiogroup" aria-label={extractText(t('dock.palette'))}>
          {palette.map((hex, i) => (
            <button
              type="button"
              className={classList('MuralPalette-swatch', page.colour === i && 'is-active')}
              style={{ '--swatch': hex }}
              role="radio"
              aria-checked={page.colour === i ? 'true' : 'false'}
              aria-label={hex}
              title={hex}
              onclick={() => page.pickColour(i)}
            />
          ))}
        </div>
        <div className="MuralDock-go">
          {this.ring(left, me.cooldown)}
          <Button
            className={classList('Button Button--primary MuralDock-place', canPlace && 'is-ready')}
            icon="fas fa-paintbrush"
            disabled={!canPlace}
            loading={page.placing}
            onclick={() => page.place()}
          >
            {ready ? t('dock.place') : t('dock.wait')}
          </Button>
        </div>
      </div>,
    ];
  }

  tileLine(page: MuralPage): Mithril.Children {
    const tile = page.selected;
    if (!tile) {
      return (
        <div className="MuralDock-tile MuralDock-tile--hint">
          <Icon name="fas fa-hand-pointer" /> {page.colour === null ? t('dock.hint') : t('dock.hint_tile')}
        </div>
      );
    }
    const info = page.info;
    const palette = page.live.state!.season.palette;
    const current = page.viewer?.colourAt(tile[0], tile[1]) ?? 0;

    return (
      <div className="MuralDock-tile">
        <span className="MuralSwatch" style={{ background: palette[current] }} />
        <span className="MuralDock-where">{t('dock.tile', { x: tile[0], y: tile[1] })}</span>
        {info === undefined ? null : info ? (
          <span className="MuralDock-who">
            {t('dock.by')} <Person row={info.user} size="small" /> · <Time at={info.placedAt} />
            {info.count > 1 ? <span className="MuralDock-count"> · {t('dock.repainted', { count: info.count })}</span> : null}
          </span>
        ) : (
          <span className="MuralDock-who">{t('tile.blank')}</span>
        )}
      </div>
    );
  }

  /** The cooldown as a ring that fills back up, with the time in the middle. */
  ring(left: number, cooldown: number): Mithril.Children {
    const r = 17;
    const c = 2 * Math.PI * r;
    const done = cooldown > 0 ? 1 - Math.min(1, left / cooldown) : 1;
    const mm = Math.floor(left / 60);
    const ss = String(left % 60).padStart(2, '0');
    const label =
      left === 0
        ? extractText(t('dock.ready'))
        : left >= 3600
          ? `${Math.floor(left / 3600)}h${String(Math.floor((left % 3600) / 60)).padStart(2, '0')}`
          : `${mm}:${ss}`;

    return (
      <div
        className={classList('MuralRing', left === 0 && 'is-ready')}
        role="timer"
        aria-live="off"
        aria-label={left === 0 ? label : extractText(t('dock.next_in', { time: label }))}
      >
        <svg viewBox="0 0 40 40" aria-hidden="true">
          <circle className="MuralRing-track" cx="20" cy="20" r={r} />
          <circle className="MuralRing-fill" cx="20" cy="20" r={r} style={{ strokeDasharray: `${c}`, strokeDashoffset: `${c * (1 - done)}` }} />
        </svg>
        <span className="MuralRing-text">{left === 0 ? <Icon name="fas fa-check" /> : label}</span>
      </div>
    );
  }
}
