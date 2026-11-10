import app from 'flarum/forum/app';
import Link from 'flarum/common/components/Link';
import Avatar from 'flarum/common/components/Avatar';
import username from 'flarum/common/helpers/username';
import humanTime from 'flarum/common/helpers/humanTime';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { fmt, person, t, type Leader, type StatsRow, type TileInfo, type UserRow } from '../api';
import type Viewer from '../Viewer';
import type { Tile } from '../Viewer';

/** A Mithril component from a function of its attrs (a bare function tag is a closure component to Mithril, not a render function). */
function fc<A>(render: (attrs: A) => Mithril.Children) {
  return () => ({ view: (vnode: { attrs: A }) => render(vnode.attrs) });
}

/** A member: avatar and name, linking to their profile. */
export const Person = fc(({ row, size }: { row: UserRow | null; size?: 'small' }) => {
  const user = person(row);
  if (!user) return <span className="MuralPerson MuralPerson--gone">{t('tile.deleted')}</span>;
  return (
    <Link className="MuralPerson" href={app.route.user(user)}>
      <Avatar user={user} className={size === 'small' ? 'Avatar--small' : ''} />
      <span className="MuralPerson-name">{username(user)}</span>
    </Link>
  );
});

export const Time = fc(({ at }: { at: string }) => humanTime(new Date(at)));

/** The top painters, with a bar for each one's share of the leader's count. */
export const Leaders = fc(({ rows }: { rows: Leader[] }) => {
  if (!rows.length) return <p className="MuralEmpty">{t('stats.no_painters')}</p>;
  const best = rows[0].tiles || 1;
  return (
    <ol className="MuralLeaders">
      {rows.map((row, i) => (
        <li className="MuralLeaders-row" style={{ '--share': `${Math.max(4, (row.tiles / best) * 100)}%` }}>
          <span className={`MuralLeaders-place MuralLeaders-place--${i + 1}`}>{i + 1}</span>
          <Person row={row.user} size="small" />
          <span className="MuralLeaders-count">{fmt(row.tiles)}</span>
        </li>
      ))}
    </ol>
  );
});

/** 24 hourly bars, oldest on the left. */
export const Hours = fc(({ stats }: { stats: StatsRow }) => {
  const max = Math.max(1, ...stats.hours);
  const from = Date.parse(stats.hoursFrom);
  const label = (h: number) => new Date(from + h * 3600000).toLocaleTimeString([], { hour: 'numeric' });
  return (
    <div className="MuralHours">
      <div className="MuralHours-bars" role="img" aria-label={extractText(t('stats.hours_label', { count: stats.last24 }))}>
        {stats.hours.map((n, h) => (
          <span className="MuralHours-bar" style={{ height: `${n ? Math.max(6, (n / max) * 100) : 2}%` }} title={`${label(h)} · ${fmt(n)}`} />
        ))}
      </div>
      <div className="MuralHours-axis">
        <span>{label(0)}</span>
        <span>{label(12)}</span>
        <span>{t('stats.now')}</span>
      </div>
    </div>
  );
});

export interface Hover {
  tile: Tile;
  x: number;
  y: number;
  info?: TileInfo | null;
}

/** "Who painted this": a small card by the pointer, kept inside the stage. */
export const HoverCard = fc(({ hover, viewer, palette }: { hover: Hover | null; viewer: Viewer | null; palette: string[] }) => {
  if (!hover || !viewer) return null;
  const box = viewer.canvas.getBoundingClientRect();
  const left = Math.min(box.width - 230, Math.max(8, hover.x - box.left + 16));
  const top = Math.min(box.height - 70, Math.max(8, hover.y - box.top + 16));

  return (
    <div className="MuralHover" style={{ left: `${left}px`, top: `${top}px` }} role="status">
      <span className="MuralSwatch" style={{ background: palette[viewer.colourAt(hover.tile[0], hover.tile[1])] }} />
      <span className="MuralHover-text">
        <span className="MuralHover-where">
          {hover.tile[0]}, {hover.tile[1]}
        </span>
        {hover.info === undefined ? (
          <span className="MuralHover-who">…</span>
        ) : hover.info ? (
          <span className="MuralHover-who">
            <Person row={hover.info.user} size="small" /> <Time at={hover.info.placedAt} />
          </span>
        ) : (
          <span className="MuralHover-who">{t('tile.blank')}</span>
        )}
      </span>
    </div>
  );
});
