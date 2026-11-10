import app from 'flarum/forum/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Stream from 'flarum/common/utils/Stream';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { call, fmt, seasonName, t, type SeasonRow, type UserRow } from '../api';
import { Person, Time } from './parts';

interface RollbackRow {
  id: number;
  tiles: number;
  createdAt: string;
  undoneAt: string | null;
  actor: UserRow | null;
  target: UserRow | null;
  filters: { x1: number; y1: number; x2: number; y2: number; from: string | null; to: string | null };
}

/** datetime-local wants local time without seconds or a zone. */
function localInput(date: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/**
 * Roll an area back: whose tiles (a member) and/or when (a time range). The
 * count comes first ("Check"), so the button that does it says exactly what
 * it will undo. Past rollbacks are listed with an Undo.
 */
export class RollbackModal extends FormModal<IFormModalAttrs & { rect: [number, number, number, number]; onclose: () => void; ondone: () => void }> {
  x1 = Stream('0');
  y1 = Stream('0');
  x2 = Stream('0');
  y2 = Stream('0');
  username = Stream('');
  from = Stream(localInput(new Date(Date.now() - 3600000)));
  to = Stream('');
  useTime = Stream(true);
  preview: { tiles: number; painters: number } | null = null;
  history: RollbackRow[] | null = null;

  oninit(vnode: Mithril.Vnode<any, this>) {
    super.oninit(vnode);
    const [x1, y1, x2, y2] = this.attrs.rect;
    this.x1(String(x1));
    this.y1(String(y1));
    this.x2(String(x2));
    this.y2(String(y2));
    this.loadHistory();
  }

  onremove(vnode: Mithril.VnodeDOM<any, this>) {
    super.onremove(vnode);
    this.attrs.onclose();
  }

  className() {
    return 'MuralModal MuralRollbackModal';
  }

  title() {
    return t('mod.rollback');
  }

  loadHistory() {
    call<{ data: RollbackRow[] }>('GET', '/rollbacks', undefined, true)
      .then((res) => (this.history = res.data))
      .catch(() => (this.history = []))
      .finally(() => m.redraw());
  }

  payload(preview: boolean): Record<string, any> {
    const iso = (v: string) => (v ? new Date(v).toISOString() : '');
    return {
      x1: this.x1(),
      y1: this.y1(),
      x2: this.x2(),
      y2: this.y2(),
      username: this.username().trim(),
      from: this.useTime() ? iso(this.from()) : '',
      to: this.useTime() ? iso(this.to()) : '',
      preview,
    };
  }

  /** Any change to the filters makes the last count stale. */
  touched() {
    this.preview = null;
  }

  content() {
    const field = (label: string, stream: Stream<string>, attrs: Record<string, any> = {}) => (
      <label className="MuralField">
        <span>{label}</span>
        <input
          className="FormControl"
          value={stream()}
          oninput={(e: InputEvent) => {
            stream((e.target as HTMLInputElement).value);
            this.touched();
          }}
          {...attrs}
        />
      </label>
    );

    return (
      <div className="Modal-body">
        <div className="Form">
          <p className="helpText">{t('mod.rollback_help')}</p>
          <fieldset className="MuralFieldset">
            <legend>{t('mod.area')}</legend>
            <div className="MuralFields MuralFields--four">
              {field('x', this.x1, { type: 'number', min: 0 })}
              {field('y', this.y1, { type: 'number', min: 0 })}
              {field('→ x', this.x2, { type: 'number', min: 0 })}
              {field('→ y', this.y2, { type: 'number', min: 0 })}
            </div>
          </fieldset>
          <div className="Form-group">
            {field(extractText(t('mod.member')), this.username, { placeholder: extractText(t('mod.member_placeholder')), autocomplete: 'off' })}
          </div>
          <div className="Form-group">
            <label className="checkbox">
              <input
                type="checkbox"
                checked={this.useTime()}
                onchange={(e: Event) => {
                  this.useTime((e.target as HTMLInputElement).checked);
                  this.touched();
                }}
              />
              {t('mod.time_range')}
            </label>
            {this.useTime() ? (
              <div className="MuralFields">
                {field(extractText(t('mod.from')), this.from, { type: 'datetime-local' })}
                {field(extractText(t('mod.to')), this.to, { type: 'datetime-local' })}
              </div>
            ) : null}
          </div>
          {this.preview ? (
            <p className="MuralModal-preview">
              {this.preview.tiles ? t('mod.preview', { count: this.preview.tiles, painters: this.preview.painters }) : t('mod.preview_none')}
            </p>
          ) : null}
          <div className="Form-group MuralModal-buttons">
            <Button className="Button" type="button" loading={this.loading && !this.preview} onclick={() => this.check()}>
              {t('mod.check')}
            </Button>
            <Button className="Button Button--danger" type="submit" disabled={!this.preview?.tiles} loading={this.loading && !!this.preview}>
              {this.preview?.tiles ? t('mod.rollback_go', { count: this.preview.tiles }) : t('mod.rollback_short')}
            </Button>
          </div>
        </div>
        {this.historyList()}
      </div>
    );
  }

  historyList(): Mithril.Children {
    if (!this.history) return <LoadingIndicator size="small" display="block" />;
    if (!this.history.length) return null;
    return (
      <div className="MuralModal-history">
        <h4>{t('mod.history')}</h4>
        <ul>
          {this.history.map((row) => (
            <li className={row.undoneAt ? 'is-undone' : ''}>
              <span className="MuralModal-historyText">
                {t('mod.history_row', { count: row.tiles })} · {row.filters.x1},{row.filters.y1} → {row.filters.x2},{row.filters.y2}
                {row.target ? [' · ', <Person row={row.target} size="small" />] : null}
                <span className="MuralModal-historyMeta">
                  <Person row={row.actor} size="small" /> · <Time at={row.createdAt} />
                </span>
              </span>
              {row.undoneAt ? (
                <span className="MuralModal-undone">{t('mod.undone')}</span>
              ) : (
                <Button className="Button Button--text" onclick={() => this.undo(row)}>
                  {t('mod.undo')}
                </Button>
              )}
            </li>
          ))}
        </ul>
      </div>
    );
  }

  check() {
    this.loading = true;
    this.preview = null;
    call<{ data: { tiles: number; painters: number } }>('POST', '/rollbacks', this.payload(true))
      .then((res) => (this.preview = res.data))
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  undo(row: RollbackRow) {
    if (!confirm(extractText(t('mod.undo_confirm', { count: row.tiles })))) return;
    call('DELETE', `/rollbacks/${row.id}`)
      .then(() => {
        app.alerts.show({ type: 'success' }, t('mod.undo_done'));
        this.loadHistory();
        this.attrs.ondone();
      })
      .catch(() => {});
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    if (!this.preview?.tiles) return;
    this.loading = true;
    call<{ data: { tiles: number } }>('POST', '/rollbacks', this.payload(false))
      .then((res) => {
        app.alerts.show({ type: 'success' }, t('mod.rollback_done', { count: res.data.tiles }));
        this.attrs.ondone();
        this.hide();
      })
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}

interface BanRow {
  user: UserRow;
  reason: string | null;
  until: string | null;
  createdAt: string;
}

/** Stop a member painting, for a number of days or until lifted. */
export class BansModal extends FormModal {
  username = Stream('');
  days = Stream('');
  reason = Stream('');
  bans: BanRow[] | null = null;

  oninit(vnode: Mithril.Vnode<any, this>) {
    super.oninit(vnode);
    call<{ data: BanRow[] }>('GET', '/bans', undefined, true)
      .then((res) => (this.bans = res.data))
      .catch(() => (this.bans = []))
      .finally(() => m.redraw());
  }

  className() {
    return 'MuralModal MuralBansModal';
  }

  title() {
    return t('mod.bans');
  }

  content() {
    return (
      <div className="Modal-body">
        <div className="Form">
          <p className="helpText">{t('mod.bans_help')}</p>
          <div className="MuralFields">
            <label className="MuralField">
              <span>{t('mod.member')}</span>
              <input
                className="FormControl"
                bidi={this.username}
                autocomplete="off"
                placeholder={extractText(t('mod.member_placeholder'))}
                required
              />
            </label>
            <label className="MuralField">
              <span>{t('mod.days')}</span>
              <input className="FormControl" type="number" min="1" max="3650" bidi={this.days} placeholder={extractText(t('mod.days_placeholder'))} />
            </label>
          </div>
          <div className="Form-group">
            <label className="MuralField">
              <span>{t('mod.reason')}</span>
              <input className="FormControl" maxlength={200} bidi={this.reason} />
            </label>
          </div>
          <div className="Form-group">
            <Button className="Button Button--danger" type="submit" loading={this.loading}>
              {t('mod.ban')}
            </Button>
          </div>
        </div>
        {this.list()}
      </div>
    );
  }

  list(): Mithril.Children {
    if (!this.bans) return <LoadingIndicator size="small" display="block" />;
    if (!this.bans.length) return <p className="MuralEmpty">{t('mod.no_bans')}</p>;
    return (
      <div className="MuralModal-history">
        <h4>{t('mod.banned_now')}</h4>
        <ul>
          {this.bans.map((ban) => (
            <li>
              <span className="MuralModal-historyText">
                <Person row={ban.user} size="small" />
                <span className="MuralModal-historyMeta">
                  {ban.until ? t('mod.until', { date: new Date(ban.until).toLocaleDateString() }) : t('mod.forever')}
                  {ban.reason ? ` · ${ban.reason}` : ''}
                </span>
              </span>
              <Button className="Button Button--text" onclick={() => this.lift(ban)}>
                {t('mod.lift')}
              </Button>
            </li>
          ))}
        </ul>
      </div>
    );
  }

  lift(ban: BanRow) {
    call('DELETE', `/bans/${ban.user.id}`)
      .then(() => {
        this.bans = (this.bans || []).filter((b) => b !== ban);
        m.redraw();
      })
      .catch(() => {});
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    this.loading = true;
    call<{ data: BanRow[] }>('POST', '/bans', { username: this.username().trim(), days: this.days(), reason: this.reason().trim() })
      .then((res) => {
        this.bans = res.data;
        this.username('');
        this.days('');
        this.reason('');
        app.alerts.show({ type: 'success' }, t('mod.ban_done'));
      })
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}

/**
 * Wrap the season up: its picture and timelapse go to the gallery and a
 * fresh canvas opens. The next one's size and palette start from the
 * admin's settings.
 */
export class EndSeasonModal extends FormModal<IFormModalAttrs & { season: SeasonRow; ondone: () => void }> {
  title_ = Stream('');
  width = Stream('');
  height = Stream('');
  palette = Stream('');
  sure = Stream(false);

  className() {
    return 'MuralModal MuralEndModal Modal--small';
  }

  title() {
    return t('mod.end_season');
  }

  content() {
    const season = this.attrs.season;
    return (
      <div className="Modal-body">
        <div className="Form">
          <p className="helpText">{t('mod.end_help', { name: seasonName(season), tiles: fmt(season.tiles) })}</p>
          <div className="Form-group">
            <label className="MuralField">
              <span>{t('mod.next_title')}</span>
              <input
                className="FormControl"
                maxlength={100}
                bidi={this.title_}
                placeholder={extractText(t('season.name', { number: season.number + 1 }))}
              />
            </label>
          </div>
          <div className="MuralFields">
            <label className="MuralField">
              <span>{t('mod.width')}</span>
              <input className="FormControl" type="number" min="16" max="512" bidi={this.width} placeholder={extractText(t('mod.from_settings'))} />
            </label>
            <label className="MuralField">
              <span>{t('mod.height')}</span>
              <input className="FormControl" type="number" min="16" max="512" bidi={this.height} placeholder={extractText(t('mod.from_settings'))} />
            </label>
          </div>
          <div className="Form-group">
            <label className="MuralField">
              <span>{t('mod.palette')}</span>
              <select className="FormControl" bidi={this.palette}>
                <option value="">{t('mod.from_settings')}</option>
                <option value="mural32">{t('palettes.mural32')}</option>
                <option value="mural16">{t('palettes.mural16')}</option>
                <option value="pastel16">{t('palettes.pastel16')}</option>
              </select>
            </label>
          </div>
          <div className="Form-group">
            <label className="checkbox">
              <input type="checkbox" checked={this.sure()} onchange={(e: Event) => this.sure((e.target as HTMLInputElement).checked)} />
              {t('mod.end_sure')}
            </label>
          </div>
          <div className="Form-group">
            <Button className="Button Button--primary Button--block" type="submit" disabled={!this.sure()} loading={this.loading}>
              {t('mod.end_go')}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    this.loading = true;
    const body: Record<string, any> = { title: this.title_().trim() };
    if (this.width()) body.width = this.width();
    if (this.height()) body.height = this.height();
    if (this.palette()) body.palette = this.palette();
    call<{ data: SeasonRow }>('POST', '/seasons', body)
      .then((res) => {
        app.alerts.show({ type: 'success' }, t('mod.end_done', { number: res.data.number }));
        this.attrs.ondone();
        this.hide();
      })
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
