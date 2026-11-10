# Mural: working rules

- **Commit straight to `main` and push. Never open a pull request on this repo.** Commit subjects are conventional (`feat:`, `fix:`); the release drafter bumps from the subject.
- `js/dist` is committed and is what forums load. After any change under `js/src`, run `npm run build` in `js/` (from a fresh `npm ci`) and commit the bundle; CI fails if it is stale.
- Publishing a release is Ernest's click. Nothing is announced until he says so.

## Design rules that exist for a reason

- **Every write to a season holds its row lock** (`lockForUpdate` on `mural_seasons` inside a transaction): placements, folds, rollbacks and the season's end. That one rule makes the cooldown race-safe (checked and set with nobody in between), makes placement ids commit in id order (so the poll's cursor, a placement id, can never skip one: never use a clock as a cursor), and keeps the snapshot single-writer. The per-member cache lock in `Canvas::place` only turns double taps away early.
- **The cooldown lives on the server** (`mural_painters.next_at`). The browser's ring is a courtesy. A member's wait is the shortest of their groups' overrides (`group_cooldowns` JSON), else the base.
- **The picture is a snapshot plus a tail.** `snapshot` = raw deflate + base64, one byte (palette index) per tile, as of placement `snapshot_upto`. Readers apply the tail; `place()` folds it in every `Canvas::FOLD_AT` placements. The state endpoint passes the stored snapshot through untouched (the browser inflates it with DecompressionStream; `?raw=1` for browsers without one).
- **The log is append-only.** A rollback marks rows with its id (`rollback_id`), never deletes, then `Canvas::rebuild` replays the season under the lock and bumps `epoch`; open canvases see the new epoch and reload. Undo clears the ids and rebuilds again. A rollback needs a member or a time range, never just an area.
- **Polling must stay cheap.** `/changes` compares two cached values (the open season's id, and the head "epoch:last id") and reads no table when nothing changed. With flarum/realtime, `Job\BroadcastPlacement` (queued, so a dead daemon never slows a tile) pushes `{s, e, p: [id, x, y, c]}` on `public`; the browser draws it at once but only moves its cursor with what a poll returns.
- **The sidebar picture is a PNG** from `/api/mural/preview`, encoded at most every 20 s (cached), with no canvas code on every page. 🚨 No route may end in `.png`/`.css`/an asset extension: nginx serves those as static files and PHP never sees them (Herd hides it). `Png` writes palette PNGs with zlib only: no GD.
- **Ending a season** closes it and opens the next in one transaction, then writes the picture (scaled to ~1024 px) and the timelapse file (`MRL1`, w, h, then 5 bytes per live placement) to the `flarum-assets` disk. The gallery re-writes missing files (`Archive::ensure`).
- Size and palette are fixed per season (tiles store palette indices); the settings apply to the next season.
- Never name an index, unique or foreign key in a migration: Laravel doesn't prefix explicit names, so two installs sharing a database collide (MySQL FK names are database-wide; PostgreSQL and SQLite index names are schema-wide). Generated names carry the prefix; the longest (`mural_placements_season_id_created_at_index`, 42) leaves room for a 22-character prefix.
- Mithril: a bare function used as a JSX tag is a closure component (it must return `{ view }`); `parts.tsx` wraps render functions with `fc()`. Don't use JSX fragments.
- forum.js only wires routes, the nav link and the sidebar picture; the canvas engine (`Viewer.ts`), live state (`Live.ts`), pages and moderation tools are one lazy chunk (`paint.tsx`).

## Testing

- `composer test:setup && composer test` (integration; SQLite and prefixed MySQL both pass), `composer analyse:phpstan`.
- `cd js && npm ci && npm run build && npm run format-check`.
- Browser checks and screenshots: `~/github/scopes/mural/` (`e2e.mjs`, `race.mjs`, `shots.mjs`) against a local forum.
- The showcase seed is `~/github/showcase/seeds/99-mural.php`.
