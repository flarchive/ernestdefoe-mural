import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Link from 'flarum/common/components/Link';
import Icon from 'flarum/common/components/Icon';
import LinkButton from 'flarum/common/components/LinkButton';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { call, fmt, seasonName, t, type SeasonRow } from '../api';
import { Person } from './parts';
import { IndexSidebar, PageStructure } from '../paint';

/** /mural/seasons: every finished mural, newest first, and the one being painted now. */
export default class GalleryPage extends Page {
  seasons: SeasonRow[] | null = null;
  current: SeasonRow | null = null;
  more = false;
  page = 0;
  loading = false;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    const title = extractText(t('gallery.title'));
    app.history.push('mural.seasons', title);
    app.setTitle(title);
    app.setTitleCount(0);
    this.load();
  }

  load() {
    this.loading = true;
    call<{ data: SeasonRow[]; current: SeasonRow; more: boolean }>('GET', '/seasons', { page: String(this.page) }, true)
      .then((res) => {
        this.seasons = [...(this.seasons || []), ...res.data];
        this.current = res.current;
        this.more = res.more;
      })
      .catch(() => (this.seasons = this.seasons || []))
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  view() {
    return (
      <PageStructure className="MuralGalleryPage" sidebar={() => <IndexSidebar />}>
        <header className="MuralPage-head">
          <div className="MuralPage-titles">
            <h2 className="MuralPage-title">
              <Icon name="fas fa-images" /> {t('gallery.title')}
            </h2>
            <div className="MuralPage-meta">{t('gallery.subtitle')}</div>
          </div>
          <div className="MuralPage-actions">
            <LinkButton className="Button Button--primary" icon="fas fa-paintbrush" href={app.route('mural')}>
              {t('gallery.paint')}
            </LinkButton>
          </div>
        </header>
        {this.body()}
      </PageStructure>
    );
  }

  body(): Mithril.Children {
    if (!this.seasons) return <LoadingIndicator display="block" />;

    return (
      <div className="MuralGallery">
        {this.current ? (
          <Link className="MuralCard MuralCard--now" href={app.route('mural')}>
            <span className="MuralCard-frame">
              <img src={`${app.forum.attribute('apiUrl')}/mural/preview`} alt="" loading="lazy" />
              <span className="MuralPill MuralPill--live MuralCard-pill">
                <span className="MuralPill-dot" aria-hidden="true" />
                {t('page.live')}
              </span>
            </span>
            <span className="MuralCard-body">
              <span className="MuralCard-title">{seasonName(this.current)}</span>
              <span className="MuralCard-meta">
                {t('gallery.now_painting', { tiles: fmt(this.current.tiles), painters: fmt(this.current.painters) })}
              </span>
            </span>
          </Link>
        ) : null}
        {this.seasons.map((season) => (
          <Link className="MuralCard" href={app.route('mural.season', { number: season.number })}>
            <span className="MuralCard-frame">
              {/* While the season's picture is being saved, the server draws one from the canvas. */}
              <img
                src={season.imageUrl || `${app.forum.attribute('apiUrl')}/mural/preview?season=${season.number}`}
                alt={seasonName(season)}
                loading="lazy"
              />
              <span className="MuralCard-play" aria-hidden="true">
                <Icon name="fas fa-play" />
              </span>
            </span>
            <span className="MuralCard-body">
              <span className="MuralCard-title">{seasonName(season)}</span>
              <span className="MuralCard-meta">
                {dateRange(season)} · {t('gallery.counts', { tiles: fmt(season.tiles), painters: fmt(season.painters) })}
              </span>
              {season.top?.length ? (
                <span className="MuralCard-top">
                  {season.top.map((row, i) => (
                    <span className={`MuralCard-leader MuralCard-leader--${i + 1}`}>
                      <Person row={row.user} size="small" />
                    </span>
                  ))}
                </span>
              ) : null}
            </span>
          </Link>
        ))}
        {!this.seasons.length ? <p className="MuralEmpty MuralGallery-empty">{t('gallery.empty')}</p> : null}
        {this.more ? (
          <div className="MuralGallery-more">
            <Button
              className="Button"
              loading={this.loading}
              onclick={() => {
                this.page++;
                this.load();
              }}
            >
              {t('gallery.more')}
            </Button>
          </div>
        ) : null}
      </div>
    );
  }
}

export function dateRange(season: SeasonRow): string {
  const opts: Intl.DateTimeFormatOptions = { month: 'short', day: 'numeric', year: 'numeric' };
  const from = new Date(season.startedAt).toLocaleDateString(undefined, opts);
  return season.endedAt ? `${from} – ${new Date(season.endedAt).toLocaleDateString(undefined, opts)}` : from;
}
