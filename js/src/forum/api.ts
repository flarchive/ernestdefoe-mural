import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';

export interface UserRow {
  id: number;
  username: string;
  displayName: string;
  slug: string;
  avatarUrl: string | null;
}

export interface SeasonRow {
  id: number;
  number: number;
  title: string | null;
  width: number;
  height: number;
  palette: string[];
  tiles: number;
  painters: number;
  startedAt: string;
  endedAt: string | null;
  imageUrl: string | null;
  timelapseUrl: string | null;
  top?: Leader[];
}

export interface Me {
  canPaint: boolean;
  cooldown: number;
  nextAt: string | null;
  banned: { until: string | null; reason: string | null } | null;
  confirmed: boolean;
  tiles: number;
  rank: number | null;
}

export interface State {
  season: SeasonRow;
  snapshot: string;
  raw?: boolean;
  tail: number[];
  upto: number;
  epoch: number;
  live: boolean;
  canModerate: boolean;
  push: boolean;
  me?: Me;
}

export interface Leader {
  user: UserRow | null;
  tiles: number;
}

export interface StatsRow {
  tiles: number;
  painters: number;
  last24: number;
  hours: number[];
  hoursFrom: string;
  active: number;
  top: Leader[];
}

export interface TileInfo {
  x: number;
  y: number;
  color: number;
  placedAt: string;
  user: UserRow | null;
  count: number;
}

export const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-mural.forum.${key}`, params);

/** Server clock minus ours: cooldowns count down by the server's time. */
let skew = 0;
export function setSkew(serverNow: string | undefined): void {
  if (serverNow) skew = Date.parse(serverNow) - Date.now();
}
export const now = () => Date.now() + skew;

export function errorText(error: any): string {
  const code = error?.response?.errors?.[0]?.code;
  const known = [
    'mural_banned',
    'mural_cooldown',
    'mural_slow_down',
    'mural_season_over',
    'mural_bad_tile',
    'mural_confirm_email',
    'mural_no_such_user',
    'mural_rollback_needs_filter',
    'mural_cannot_ban',
    'mural_not_found',
  ];
  return extractText(known.includes(code) ? t(`errors.${code.slice(6)}`) : app.translator.trans('core.lib.error.generic_message'));
}

export function call<T>(method: 'GET' | 'POST' | 'DELETE', path: string, data?: Record<string, any>, quiet = false): Promise<T> {
  const url = app.forum.attribute<string>('apiUrl') + '/mural' + path;
  const query = method === 'GET' && data ? '?' + new URLSearchParams(data).toString() : '';
  return app.request<T>({
    method,
    url: url + query,
    body: method === 'GET' ? undefined : data,
    background: method === 'GET',
    errorHandler: (error: any) => {
      if (!quiet) app.alerts.show({ type: 'error' }, errorText(error));
      throw error;
    },
  });
}

/** Members arrive as compact rows; the store lets avatar() and username() draw them the usual way. */
export function person(row: UserRow | null): any {
  if (!row) return null;
  return app.store.pushObject({
    type: 'users',
    id: String(row.id),
    attributes: { username: row.username, displayName: row.displayName, slug: row.slug, avatarUrl: row.avatarUrl },
  } as any);
}

/** The picture's bytes: raw deflate in base64, inflated by the browser. */
export async function inflate(b64: string, raw = false): Promise<Uint8Array> {
  const bin = atob(b64);
  const bytes = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
  if (raw) return bytes;
  const stream = new Blob([bytes]).stream().pipeThrough(new (window as any).DecompressionStream('deflate-raw'));
  return new Uint8Array(await new Response(stream).arrayBuffer());
}

export const canInflate = () => typeof (window as any).DecompressionStream === 'function';

/** Palette as little-endian RGBA words, ready for an ImageData's Uint32 view. */
export function paletteWords(palette: string[]): Uint32Array {
  const words = new Uint32Array(palette.length);
  palette.forEach((hex, i) => {
    const n = parseInt(hex.slice(1), 16);
    words[i] = (0xff << 24) | ((n & 0xff) << 16) | (n & 0xff00) | ((n >> 16) & 0xff);
  });
  return words;
}

export function seasonName(season: SeasonRow): string {
  return extractText(season.title || t('season.name', { number: season.number }));
}

export const fmt = (n: number) => n.toLocaleString(app.translator.getLocale?.() || undefined);
