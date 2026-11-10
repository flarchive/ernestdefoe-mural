<?php

namespace ErnestDefoe\Mural;

use Carbon\Carbon;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;

/**
 * Numbers for the stats panel, cached for half a minute per season: one
 * busy canvas with a hundred viewers is still one count every 30 seconds.
 */
class Stats
{
    private const TTL = 30;

    public function __construct(private readonly ConnectionInterface $db, private readonly Cache $cache, private readonly Presenter $presenter)
    {
    }

    /**
     * The numbers are cached for everyone; the top painters are resolved for
     * this viewer (only members they may see), one query.
     *
     * @return array<string, mixed>
     */
    public function for(Season $season, User $actor, int $top = 10): array
    {
        $key = 'ernestdefoe-mural.stats.'.$season->id.'.'.$season->epoch.'.'.$top;
        $stats = $this->cache->remember($key, $season->isOpen() ? self::TTL : 3600, fn () => $this->compute($season, $top));
        $stats['top'] = $this->people($stats['top'], $actor);

        return $stats;
    }

    /**
     * [user id, tiles] rows as members this viewer may see.
     *
     * @param list<array{int, int}> $rows
     * @return list<array{user: array<string, mixed>|null, tiles: int}>
     */
    private function people(array $rows, User $actor): array
    {
        $users = User::query()->whereVisibleTo($actor)->whereIn('id', array_column($rows, 0))->get()->keyBy('id');
        $out = [];
        foreach ($rows as [$id, $tiles]) {
            if ($users->has($id)) {
                $out[] = ['user' => $this->presenter->user($users->get($id)), 'tiles' => $tiles];
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function compute(Season $season, int $top): array
    {
        $now = Carbon::now();
        $since = $now->copy()->subHours(23)->startOfHour();
        $hours = array_fill(0, 24, 0);
        if ($season->isOpen()) {
            foreach (Placement::query()->where('season_id', $season->id)->where('created_at', '>=', $since)->toBase()->select('created_at')->cursor() as $row) {
                $index = (int) floor(($this->timestamp($row->created_at) - $since->getTimestamp()) / 3600);
                if ($index >= 0 && $index < 24) {
                    $hours[$index]++;
                }
            }
        }

        $leaders = $this->db->table('mural_painters')->where('season_id', $season->id)->where('tiles', '>', 0)
            ->orderByDesc('tiles')->orderBy('last_at')->limit($top)->get(['user_id', 'tiles']);

        return [
            'tiles' => $season->placements_count,
            'painters' => $season->painters_count,
            'last24' => array_sum($hours),
            'hours' => $hours,
            'hoursFrom' => $since->toIso8601String(),
            'active' => $season->isOpen()
                ? $this->db->table('mural_painters')->where('season_id', $season->id)->where('last_at', '>=', $now->copy()->subMinutes(15))->count()
                : 0,
            // Ids only: who a viewer may see is decided per request (people()).
            'top' => $leaders->map(fn ($row) => [(int) $row->user_id, (int) $row->tiles])->all(),
        ];
    }

    private function timestamp(mixed $value): int
    {
        return $value instanceof \DateTimeInterface ? $value->getTimestamp() : (int) strtotime((string) $value.' UTC');
    }

    /**
     * The top painters of finished seasons. They never change once a season
     * has ended, so each season's ids are worked out once (one indexed query,
     * portable to MySQL 5.7, which has no window functions) and kept; members
     * are then resolved for this viewer in one query.
     *
     * @param list<int> $seasonIds
     * @return array<int, list<array{user: array<string, mixed>|null, tiles: int}>>
     */
    public function leaders(array $seasonIds, int $top, User $actor): array
    {
        $rows = [];
        foreach ($seasonIds as $id) {
            $rows[$id] = $this->cache->rememberForever('ernestdefoe-mural.leaders.'.$id.'.'.$top, fn () => $this->db->table('mural_painters')
                ->where('season_id', $id)->where('tiles', '>', 0)->orderByDesc('tiles')->orderBy('last_at')->limit($top)
                ->get(['user_id', 'tiles'])->map(fn ($row) => [(int) $row->user_id, (int) $row->tiles])->all());
        }

        $users = User::query()->whereVisibleTo($actor)->whereIn('id', array_unique(array_merge([], ...array_map(fn ($r) => array_column($r, 0), array_values($rows)))))->get()->keyBy('id');
        $leaders = [];
        foreach ($rows as $id => $list) {
            foreach ($list as [$userId, $tiles]) {
                if ($users->has($userId)) {
                    $leaders[$id][] = ['user' => $this->presenter->user($users->get($userId)), 'tiles' => $tiles];
                }
            }
        }

        return $leaders;
    }

    /** A member's own line: their tiles this season, their place and when they may paint again. @return array{tiles: int, rank: int|null, nextAt: string|null} */
    public function mine(Season $season, User $user): array
    {
        $row = $this->db->table('mural_painters')->where('season_id', $season->id)->where('user_id', $user->id)->first(['tiles', 'next_at']);
        $tiles = (int) ($row->tiles ?? 0);
        $next = $row && $row->next_at ? Carbon::parse($row->next_at) : null;

        return [
            'tiles' => $tiles,
            'nextAt' => $next && $next->isFuture() ? $next->toIso8601String() : null,
            'rank' => $tiles > 0 ? $this->db->table('mural_painters')->where('season_id', $season->id)->where('tiles', '>', $tiles)->count() + 1 : null,
        ];
    }
}
