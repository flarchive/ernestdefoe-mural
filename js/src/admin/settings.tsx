import app from 'flarum/admin/app';
import type Mithril from 'mithril';
import { PRESETS, parsePalette } from '../common/palettes';

const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-mural.admin.${key}`, params);
const key = (name: string) => `ernestdefoe-mural.${name}`;

export function settingsPage(): void {
  app.registry
    .for('ernestdefoe-mural')
    .registerSetting({ setting: key('nav'), type: 'boolean', label: t('settings.nav') })
    .registerSetting({ setting: key('sidebar_widget'), type: 'boolean', label: t('settings.widget'), help: t('settings.widget_help') })
    .registerSetting({
      setting: key('allow_indexing'),
      type: 'boolean',
      label: t('settings.allow_indexing'),
      help: t('settings.allow_indexing_help'),
    })
    .registerSetting({
      setting: key('cooldown'),
      type: 'number',
      min: 0,
      max: 86400,
      label: t('settings.cooldown'),
      help: t('settings.cooldown_help'),
    })
    .registerSetting(function (this: any) {
      return groupCooldowns(this.setting(key('group_cooldowns')));
    })
    .registerSetting(function (this: any) {
      return <h3 className="MuralAdmin-heading">{t('settings.next_season')}</h3>;
    })
    .registerSetting({ setting: key('width'), type: 'number', min: 16, max: 512, label: t('settings.width') })
    .registerSetting({ setting: key('height'), type: 'number', min: 16, max: 512, label: t('settings.height'), help: t('settings.size_help') })
    .registerSetting(function (this: any) {
      return palette(this.setting(key('palette')));
    })
    .registerPermission({ permission: 'mural.view', icon: 'fas fa-palette', label: t('permissions.view'), allowGuest: true }, 'view', 60)
    .registerPermission({ permission: 'mural.paint', icon: 'fas fa-paintbrush', label: t('permissions.paint') }, 'reply', 60)
    .registerPermission({ permission: 'mural.moderate', icon: 'fas fa-broom', label: t('permissions.moderate') }, 'moderate', 60);
}

/** Groups that wait less (or longer) than the base cooldown; empty = the base applies. */
function groupCooldowns(stream: any): Mithril.Children {
  let map: Record<string, number> = {};
  try {
    map = JSON.parse(stream() || '{}') || {};
  } catch {
    map = {};
  }
  const groups = app.store.all<any>('groups').filter((g) => g.id() !== '2'); // guests can't paint
  const set = (id: string, value: string) => {
    const next = { ...map };
    if (value === '') delete next[id];
    else next[id] = Math.max(0, Math.min(86400, parseInt(value, 10) || 0));
    stream(JSON.stringify(next));
  };

  return (
    <div className="Form-group">
      <label>{t('settings.group_cooldowns')}</label>
      <div className="helpText">{t('settings.group_cooldowns_help')}</div>
      <div className="MuralAdmin-groups">
        {groups.map((group) => [
          <label for={`mural-cd-${group.id()}`}>{group.namePlural()}</label>,
          <input
            id={`mural-cd-${group.id()}`}
            className="FormControl"
            type="number"
            min="0"
            max="86400"
            placeholder={t('settings.group_cooldown_placeholder') as string}
            value={map[group.id()] ?? ''}
            oninput={(e: InputEvent) => set(group.id(), (e.target as HTMLInputElement).value)}
          />,
        ])}
      </div>
    </div>
  );
}

function palette(stream: any): Mithril.Children {
  const value: string = stream() || 'mural32';
  const preset = PRESETS[value] ? value : 'custom';
  const colours = parsePalette(value);

  return (
    <div className="Form-group">
      <label for="mural-palette">{t('settings.palette')}</label>
      <select
        id="mural-palette"
        className="FormControl"
        value={preset}
        onchange={(e: Event) => {
          const v = (e.target as HTMLSelectElement).value;
          stream(v === 'custom' ? colours.join(', ') : v);
        }}
      >
        {Object.keys(PRESETS).map((name) => (
          <option value={name}>{t(`palettes.${name}`)}</option>
        ))}
        <option value="custom">{t('palettes.custom')}</option>
      </select>
      {preset === 'custom' ? (
        <textarea
          className="FormControl MuralAdmin-custom"
          rows={3}
          value={value}
          placeholder="#ffffff, #000000, #d7263d, …"
          oninput={(e: InputEvent) => stream((e.target as HTMLTextAreaElement).value)}
        />
      ) : null}
      <div className="MuralAdmin-swatches" aria-hidden="true">
        {colours.map((c) => (
          <span style={{ background: c }} title={c} />
        ))}
      </div>
      <div className="helpText">{t('settings.palette_help', { count: colours.length })}</div>
    </div>
  );
}
