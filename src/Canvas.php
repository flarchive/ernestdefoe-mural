<?php

namespace ErnestDefoe\Mural;

use Carbon\Carbon;
use ErnestDefoe\Mural\Job\BroadcastPlacement;
use Flarum\Group\Group;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;

/**
 * The canvas: who may place what, and what it looks like.
 *
 * 🚨 Every write to a season (a placement, a fold, a rollback, the end of
 * the season) happens inside a transaction that holds the season's row
 * lock. That one rule gives three things: a member's cooldown is checked
 * and set with nobody else in between (the per-member lock in front of it
 * only turns away double taps early), placement ids commit in id order so a
 * polling cursor by id can never skip one, and the snapshot is only ever
 * rewritten by the one writer.
 */
class Canvas
{
    /** Placements after the snapshot before they are folded into it. */
    public const FOLD_AT = 256;

    private const HEAD_TTL = 86400;
    private const CURRENT = 'ernestdefoe-mural.current';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly SettingsRepositoryInterface $settings,
        private readonly Cache $cache,
        private readonly Container $container,
        private readonly Queue $queue,
    ) {
    }

    /** The open season, started on first use. */
    public function current(bool $withSnapshot = false, bool $retry = true): Season
    {
        $query = Season::query()->whereNull('ended_at')->orderByDesc('number');
        $season = $withSnapshot ? $query->first() : $query->first(Season::LIGHT);
        if ($season) {
            return $season;
        }
        if (($started = $this->start(null, null, null, null)) || ! $retry) {
            return $started ?? throw new \RuntimeException('Mural could not start a season.');
        }

        return $this->current($withSnapshot, false);
    }

    /**
     * A new season with the admin's size and palette unless told otherwise.
     * Null when another request started one first (the caller reads that one).
     */
    public function start(?string $title, ?int $width, ?int $height, ?string $palette, int $number = 0): ?Season
    {
        $width = $this->size($width ?? (int) $this->settings->get(Settings::WIDTH));
        $height = $this->size($height ?? (int) $this->settings->get(Settings::HEIGHT));
        $colours = Palette::parse($palette ?? $this->settings->get(Settings::PALETTE));

        $season = new Season();
        $season->number = $number ?: ((int) Season::query()->max('number')) + 1;
        $season->title = $title !== null && trim($title) !== '' ? mb_substr(trim($title), 0, 100) : null;
        $season->width = $width;
        $season->height = $height;
        $season->palette = (string) json_encode($colours);
        $season->snapshot = Season::encode(str_repeat("\0", $width * $height));
        $season->snapshot_upto = 0;
        $season->epoch = 0;
        $season->placements_count = 0;
        $season->painters_count = 0;
        $season->started_at = Carbon::now();

        try {
            $season->save();
        } catch (QueryException) {
            return null; // the unique number: someone else started it a moment ago
        }
        $this->forgetCurrent();

        return $season;
    }

    /** The open season's id, cached: what the poll compares to learn a season ended. */
    public function currentId(): int
    {
        return (int) $this->cache->remember(self::CURRENT, 3600, fn () => $this->current()->id);
    }

    public function forgetCurrent(): void
    {
        $this->cache->forget(self::CURRENT);
    }

    public function size(int $value): int
    {
        return $value < 1 ? Settings::DEFAULT_SIZE : max(Settings::MIN_SIZE, min(Settings::MAX_SIZE, $value));
    }

    /**
     * Live placements after the snapshot, oldest first: what a reader applies on top of it.
     *
     * @return list<array{int, int, int, int}> [id, x, y, colour]
     */
    public function tail(Season $season): array
    {
        return $this->tailAfter($season->id, $season->snapshot_upto);
    }

    /**
     * Live placements after a placement id, oldest first.
     *
     * @return list<array{int, int, int, int}> [id, x, y, colour]
     */
    public function tailAfter(int $seasonId, int $after, int $limit = 0): array
    {
        $query = Placement::live($seasonId)
            ->where('id', '>', $after)
            ->orderBy('id')
            ->toBase()
            ->select(['id', 'x', 'y', 'color']);
        if ($limit) {
            $query->limit($limit);
        }

        return $query->get()->map(fn ($p) => [(int) $p->id, (int) $p->x, (int) $p->y, (int) $p->color])->all();
    }

    /** The picture right now, one byte per tile. */
    public function pixels(Season $season): string
    {
        if (! isset($season->snapshot)) {
            $season = Season::query()->findOrFail($season->id);
        }
        $pixels = $season->pixelsFromSnapshot();
        foreach ($this->tail($season) as [, $x, $y, $c]) {
            $pixels[$y * $season->width + $x] = chr($c);
        }

        return $pixels;
    }

    /**
     * "epoch:last id": what an open canvas compares to know there is nothing new.
     *
     * 🚨 On a miss both halves come from the tables, never from a model the
     * caller built: a cached wrong epoch (say 0 after a rollback made it 1)
     * sends every open canvas into a reset-reload loop until it expires.
     */
    public function head(int $seasonId): string
    {
        $key = 'ernestdefoe-mural.head.'.$seasonId;
        $head = $this->cache->get($key);
        if (! is_string($head)) {
            $epoch = (int) Season::query()->whereKey($seasonId)->value('epoch');
            $head = $epoch.':'.(int) Placement::query()->where('season_id', $seasonId)->max('id');
            $this->cache->put($key, $head, self::HEAD_TTL);
        }

        return $head;
    }

    private function setHead(Season $season, int $lastId): void
    {
        $this->cache->put('ernestdefoe-mural.head.'.$season->id, $season->epoch.':'.$lastId, self::HEAD_TTL);
    }

    /** Seconds between tiles for this member: the shortest their groups allow. */
    public function cooldownFor(User $user): int
    {
        $base = $this->settings->get(Settings::COOLDOWN);
        $base = $base === null || $base === '' ? Settings::DEFAULT_COOLDOWN : (int) $base;
        $overrides = json_decode((string) $this->settings->get(Settings::GROUP_COOLDOWNS), true);
        $best = $base;

        if (is_array($overrides) && $overrides) {
            $groups = $user->isGuest() ? [] : array_merge([Group::MEMBER_ID], $user->groups->pluck('id')->all());
            foreach ($groups as $id) {
                if (isset($overrides[$id]) && is_numeric($overrides[$id])) {
                    $best = min($best, (int) $overrides[$id]);
                }
            }
        }

        return max(0, min(Settings::MAX_COOLDOWN, $best));
    }

    public function banFor(User $user): ?Ban
    {
        return $user->isGuest() ? null : Ban::active()->find($user->id);
    }

    /**
     * Place one tile for a member, or say why not.
     *
     * @return array{placement: Placement, season: Season, nextAt: Carbon}
     */
    public function place(User $actor, int $x, int $y, int $color): array
    {
        if ($ban = $this->banFor($actor)) {
            throw new MuralException('mural_banned', 403, ['until' => $ban->until?->toIso8601String()]);
        }

        $cooldown = $this->cooldownFor($actor);
        $this->current(); // the very first tile on a fresh install starts season 1
        $lock = $this->userLock($actor);
        if ($lock && ! $lock->get()) {
            throw new MuralException('mural_slow_down', 429);
        }

        try {
            $result = $this->db->transaction(function () use ($actor, $x, $y, $color, $cooldown) {
                $season = Season::query()->whereNull('ended_at')->orderByDesc('number')->lockForUpdate()->first(Season::LIGHT);
                // The locked read sees the latest commit: a season ended while this waited is caught here.
                if (! $season || $season->ended_at !== null) {
                    throw new MuralException('mural_season_over', 409);
                }
                if ($x < 0 || $y < 0 || $x >= $season->width || $y >= $season->height || $color < 0 || $color >= count($season->colours())) {
                    throw new MuralException('mural_bad_tile', 422);
                }

                $now = Carbon::now();
                $painters = $this->db->table('mural_painters');
                $painter = (clone $painters)->where('season_id', $season->id)->where('user_id', $actor->id)->lockForUpdate()->first();
                if ($painter && $painter->next_at && Carbon::parse($painter->next_at)->gt($now)) {
                    throw new MuralException('mural_cooldown', 429, ['nextAt' => Carbon::parse($painter->next_at)->toIso8601String()]);
                }

                $placement = new Placement();
                $placement->season_id = $season->id;
                $placement->user_id = $actor->id;
                $placement->x = $x;
                $placement->y = $y;
                $placement->color = $color;
                $placement->created_at = $now;
                $placement->save();

                $nextAt = $now->copy()->addSeconds($cooldown);
                $row = ['last_at' => $now, 'next_at' => $nextAt];
                if ($painter) {
                    (clone $painters)->where('season_id', $season->id)->where('user_id', $actor->id)
                        ->update($row + ['tiles' => $this->plusOne('tiles')]);
                } else {
                    (clone $painters)->insert($row + ['season_id' => $season->id, 'user_id' => $actor->id, 'tiles' => 1]);
                }

                $counts = ['placements_count' => $this->plusOne('placements_count')];
                if (! $painter) {
                    $counts['painters_count'] = $this->plusOne('painters_count');
                }
                Season::query()->whereKey($season->id)->update($counts);

                if ($placement->id - $season->snapshot_upto >= self::FOLD_AT) {
                    $this->fold($season);
                }

                return ['placement' => $placement, 'season' => $season, 'nextAt' => $nextAt];
            });
        } finally {
            $lock?->release();
        }

        $this->afterCommit(function () use ($result) {
            $this->setHead($result['season'], $result['placement']->id);
            $this->broadcast($result['season'], $result['placement']);
        });
        $this->container->make(Integration\Cadence::class)->placed($actor, $result['placement']->created_at);

        return $result;
    }

    /** Fold the tail into the snapshot. Caller holds the season's row lock. */
    private function fold(Season $season): void
    {
        $fresh = Season::query()->findOrFail($season->id);
        $tail = $this->tail($fresh);
        if (! $tail) {
            return;
        }
        $pixels = $fresh->pixelsFromSnapshot();
        foreach ($tail as [, $x, $y, $c]) {
            $pixels[$y * $fresh->width + $x] = chr($c);
        }
        Season::query()->whereKey($season->id)->update(['snapshot' => Season::encode($pixels), 'snapshot_upto' => end($tail)[0]]);
    }

    /**
     * Replay a season's live placements into a new snapshot, after history
     * changed (a rollback or its undo). Caller holds the season's row lock.
     */
    public function rebuild(Season $season): Season
    {
        $pixels = str_repeat("\0", $season->width * $season->height);
        $width = $season->width;
        Placement::live($season->id)->toBase()->select(['id', 'x', 'y', 'color'])->orderBy('id')
            ->chunkById(5000, function ($rows) use (&$pixels, $width) {
                foreach ($rows as $p) {
                    $pixels[$p->y * $width + $p->x] = chr((int) $p->color);
                }
            });
        $upto = (int) Placement::query()->where('season_id', $season->id)->max('id');

        Season::query()->whereKey($season->id)->update([
            'snapshot' => Season::encode($pixels),
            'snapshot_upto' => $upto,
            'epoch' => $season->epoch + 1,
            'placements_count' => Placement::live($season->id)->count(),
        ]);
        $season = Season::query()->findOrFail($season->id);
        // Announced once the caller's transaction commits: a viewer told about a
        // new epoch must be able to read it.
        $this->afterCommit(function () use ($season, $upto) {
            $this->setHead($season, $upto);
            $this->broadcastReset($season);
        });

        return $season;
    }

    /**
     * Redraw one rectangle from the log after its history changed (a
     * rollback or its undo), keeping the rest of the picture as it is.
     * Caller holds the season's row lock. Each tile in the area takes its
     * newest live placement (one grouped query on the tile index), or blank.
     *
     * @param array{int, int, int, int} $area x1, y1, x2, y2 (inclusive)
     * @param int $tilesDelta live placements gained (undo) or lost (rollback)
     */
    public function rebuildArea(Season $season, array $area, int $tilesDelta): Season
    {
        [$x1, $y1, $x2, $y2] = $area;
        $fresh = Season::query()->findOrFail($season->id);
        $pixels = $this->pixels($fresh);
        $width = $fresh->width;

        $blank = str_repeat("\0", $x2 - $x1 + 1);
        for ($y = $y1; $y <= $y2; $y++) {
            $pixels = substr_replace($pixels, $blank, $y * $width + $x1, strlen($blank));
        }

        $newest = Placement::live($season->id)->whereBetween('x', [$x1, $x2])->whereBetween('y', [$y1, $y2])
            ->groupBy('y', 'x')->toBase()->selectRaw('max(id) as id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        foreach (array_chunk($newest, 2000) as $ids) {
            foreach (Placement::query()->whereIn('id', $ids)->toBase()->get(['x', 'y', 'color']) as $p) {
                $pixels[$p->y * $width + $p->x] = chr((int) $p->color);
            }
        }

        $upto = (int) Placement::query()->where('season_id', $season->id)->max('id');
        Season::query()->whereKey($season->id)->update([
            'snapshot' => Season::encode($pixels),
            'snapshot_upto' => $upto,
            'epoch' => $fresh->epoch + 1,
            'placements_count' => max(0, $fresh->placements_count + $tilesDelta),
        ]);
        $season = Season::query()->findOrFail($season->id, Season::LIGHT);
        $this->afterCommit(function () use ($season, $upto) {
            $this->setHead($season, $upto);
            $this->broadcastReset($season);
        });

        return $season;
    }

    /**
     * Recount the live tiles of these members after a rollback: one grouped
     * UPDATE per 500 members, the count taken in the database.
     *
     * @param list<int|null> $userIds
     */
    public function recountPainters(Season $season, array $userIds): void
    {
        $grammar = $this->db->getQueryGrammar();
        $placements = $grammar->wrapTable('mural_placements');
        $painters = $grammar->wrapTable('mural_painters');
        $count = $this->db->raw("(select count(*) from $placements where $placements.season_id = $painters.season_id"
            ." and $placements.user_id = $painters.user_id and $placements.rollback_id is null)");

        foreach (array_chunk(array_values(array_unique(array_filter($userIds))), 500) as $chunk) {
            $this->db->table('mural_painters')->where('season_id', $season->id)->whereIn('user_id', $chunk)->update(['tiles' => $count]);
        }
    }

    private function plusOne(string $column): \Illuminate\Contracts\Database\Query\Expression
    {
        return $this->db->raw($this->db->getQueryGrammar()->wrap($column).' + 1');
    }

    private function userLock(User $actor): ?\Illuminate\Contracts\Cache\Lock
    {
        $store = $this->cache instanceof \Illuminate\Cache\Repository ? $this->cache->getStore() : null;

        return $store instanceof LockProvider ? $store->lock('ernestdefoe-mural.place.'.$actor->id, 10) : null;
    }

    /** Run once the open transaction (if any) commits. */
    public function afterCommit(callable $callback): void
    {
        $this->db instanceof \Illuminate\Database\Connection ? $this->db->afterCommit($callback) : $callback();
    }

    /**
     * Push tiles through flarum/realtime only when it can't cost a painter
     * anything or leak anything: realtime is there, a real queue carries the
     * push (on the sync queue a dead daemon would hold up the placement), and
     * the mural is public (realtime has no members-only channel an extension
     * can use, so a closed forum's canvases poll instead).
     */
    public function realtime(): bool
    {
        return class_exists(\Pusher\Pusher::class) && $this->container->bound(\Pusher\Pusher::class)
            && ! $this->queue instanceof \Illuminate\Queue\SyncQueue
            && Access::isPublic();
    }

    /** Queued, and only when flarum/realtime is there to carry it: a dead daemon never slows a placement. */
    private function broadcast(Season $season, Placement $placement): void
    {
        if ($this->realtime()) {
            $this->queue->push(new BroadcastPlacement(['s' => $season->id, 'e' => $season->epoch, 'p' => [$placement->id, $placement->x, $placement->y, $placement->color]]));
        }
    }

    public function broadcastReset(Season $season): void
    {
        if ($this->realtime()) {
            $this->queue->push(new BroadcastPlacement(['s' => $season->id, 'e' => $season->epoch, 'reset' => true]));
        }
    }
}
