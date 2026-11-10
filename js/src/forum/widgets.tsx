import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Link from 'flarum/common/components/Link';
import Icon from 'flarum/common/components/Icon';
import classList from 'flarum/common/utils/classList';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { fof, hasFofWidgets, placed } from '../common/fofWidgets';

/**
 * "Live mural": the canvas as it is now, a click away from painting.
 *
 * Just an <img> of /api/mural/preview, which the server encodes at most
 * every 20 seconds however many people look; the picture refreshes every
 * minute while the tab is visible. Offered to every widget host there is
 * (Bespoke, FoF Forum Widgets), and to the stock sidebar otherwise.
 */

const EXT = 'ernestdefoe-mural';
const KEY = 'muralLive';
const REFRESH = 60000;

// One clock for every copy on the page (a host may remount its widgets at will).
let tick = Math.floor(Date.now() / REFRESH);
let timer: number | undefined;
let mounted = 0;

function start() {
  if (mounted++ === 0) {
    timer = window.setInterval(() => {
      if (document.hidden) return;
      tick++;
      m.redraw();
    }, REFRESH);
  }
}

function stop() {
  if (--mounted === 0) clearInterval(timer);
}

export function widgetPlaced(): boolean {
  return hasFofWidgets() && placed(app, EXT, KEY);
}

export class MuralWidget extends Component<{ compact?: boolean; heading?: string }> {
  oncreate(vnode: Mithril.VnodeDOM<{ compact?: boolean; heading?: string }, this>) {
    super.oncreate(vnode);
    start();
  }

  onremove(vnode: Mithril.VnodeDOM<{ compact?: boolean; heading?: string }, this>) {
    super.onremove(vnode);
    stop();
  }

  view() {
    if (!app.forum.attribute('muralCanView')) return null;
    const src = `${app.forum.attribute('apiUrl')}/mural/preview?v=${tick}`;

    return (
      <div className={classList('MuralWidget', this.attrs.compact && 'MuralWidget--compact')}>
        <Link className="MuralWidget-link" href={app.route('mural')}>
          <span className="MuralWidget-head">
            <span className="MuralWidget-live" aria-hidden="true" />
            <span className="MuralWidget-title">{this.attrs.heading || app.translator.trans('ernestdefoe-mural.forum.widget.title')}</span>
          </span>
          <span className="MuralWidget-frame">
            <img src={src} alt={extractText(app.translator.trans('ernestdefoe-mural.forum.widget.alt'))} loading="lazy" decoding="async" />
          </span>
          <span className="MuralWidget-cta">
            <Icon name="fas fa-paintbrush" /> {app.translator.trans('ernestdefoe-mural.forum.widget.cta')}
          </span>
        </Link>
      </div>
    );
  }
}

export function registerWidgets(): void {
  const flarum = (globalThis as any).flarum;
  const has = (id: string) => !!flarum?.extensions && id in flarum.extensions;

  if (hasFofWidgets() && (app as any).widgets) {
    const Widgets = fof('common/extend/Widgets');
    const WidgetBase = fof('common/components/Widget');

    // A version whose internals moved: decline quietly rather than take the forum's JS down.
    if (Widgets && WidgetBase) {
      class FofMuralWidget extends WidgetBase {
        className() {
          return 'MuralFofWidget';
        }
        icon() {
          return 'fas fa-palette';
        }
        title() {
          return app.translator.trans('ernestdefoe-mural.forum.widget.title');
        }
        content() {
          return <MuralWidget />;
        }
      }

      new Widgets()
        .add({
          key: KEY,
          component: FofMuralWidget,
          // 🚨 Opt-in: only where the admin placed it in FoF's layout editor (see common/fofWidgets.ts).
          isDisabled: () => !placed(app, EXT, KEY) || !app.forum.attribute('muralCanView'),
          isUnique: true,
          placement: 'end',
          position: 1,
        })
        .extend(app, EXT);
    }
  }

  if (has('ernestdefoe-bespoke')) {
    const g = globalThis as any;
    g.BespokeWidgetQueue = g.BespokeWidgetQueue || [];
    // Labels are translation KEYS: Bespoke resolves them itself, in the reader's locale.
    g.BespokeWidgetQueue.push({
      type: 'mural-live',
      label: 'ernestdefoe-mural.forum.widget.name',
      icon: 'fas fa-palette',
      zones: ['sidebar', 'above-list', 'below-list', 'footer'],
      schema: [{ key: 'heading', type: 'text', label: 'ernestdefoe-mural.forum.widget.heading', default: '' }],
      component: {
        view(vnode: any) {
          return <MuralWidget heading={vnode.attrs?.settings?.heading} />;
        },
      },
    });
  }
}
