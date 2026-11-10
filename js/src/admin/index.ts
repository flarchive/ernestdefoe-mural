import app from 'flarum/admin/app';
import { registerEditorWidgets } from '../common/fofWidgets';
import { settingsPage } from './settings';

/*
 * Mural's admin: the next season's size and palette, the cooldown (with
 * shorter ones for chosen groups), where it shows, and permissions. Seasons,
 * rollbacks and bans are run from the mural itself, where they are seen.
 */
app.initializers.add('ernestdefoe-mural', () => {
  settingsPage();

  // FoF's layout editor lists only widgets the admin registers too.
  registerEditorWidgets(app, 'ernestdefoe-mural', [
    { key: 'muralLive', icon: 'fas fa-palette', title: () => app.translator.trans('ernestdefoe-mural.admin.widgets.live') },
  ]);
});
