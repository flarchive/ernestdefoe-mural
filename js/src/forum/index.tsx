import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import type ItemList from 'flarum/common/utils/ItemList';
import LinkButton from 'flarum/common/components/LinkButton';
import type Mithril from 'mithril';
import { MuralWidget, registerWidgets, widgetPlaced } from './widgets';

/*
 * forum.js is loaded on every page, so it only wires things up: the routes,
 * the nav link and the little live picture in the sidebar (an <img> of a
 * server-drawn PNG, no canvas code). The canvas, the gallery and the
 * moderation tools are one chunk (paint), fetched when first opened.
 */

const page = (name: 'MuralPage' | 'GalleryPage' | 'SeasonPage') => () =>
  import('./paint').then(async (paint) => (await paint.loadCore(), { default: paint[name] }));

app.initializers.add('ernestdefoe/mural', () => {
  app.routes['mural'] = { path: '/mural', component: page('MuralPage') };
  app.routes['mural.seasons'] = { path: '/mural/seasons', component: page('GalleryPage') };
  app.routes['mural.season'] = { path: '/mural/seasons/:number', component: page('SeasonPage') };

  // String form: IndexSidebar lives in a lazy chunk, and extending an imported copy patches one core never renders.
  extend('flarum/forum/components/IndexSidebar', 'navItems', function (items: ItemList<Mithril.Children>) {
    if (!app.forum.attribute('muralCanView') || !app.forum.attribute('muralNav')) return;
    items.add(
      'mural',
      LinkButton.component({ href: app.route('mural'), icon: 'fas fa-palette' }, app.translator.trans('ernestdefoe-mural.forum.nav')),
      78
    );
  });

  // The stock-sidebar picture: stands down where a widget host places it instead, and on the mural's own pages.
  extend('flarum/forum/components/IndexSidebar', 'items', function (items: ItemList<Mithril.Children>) {
    if (!app.forum.attribute('muralCanView') || !app.forum.attribute('muralWidget') || widgetPlaced()) return;
    if (String(app.current.get('routeName') || '').startsWith('mural')) return;
    items.add('muralWidget', <MuralWidget compact />, -50);
  });

  registerWidgets();
});
