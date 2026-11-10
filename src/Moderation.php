<?php

namespace ErnestDefoe\Mural;

use Carbon\Carbon;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Rollbacks: put an area of the canvas back to how it was before some
 * member's tiles, or before a stretch of time. Rows are marked with the
 * rollback's id, never deleted, so a rollback can be undone. Only the
 * rollback's rectangle is redrawn (Canvas::rebuildArea: the newest live row
 * per tile), under the season's lock, so painting waits milliseconds, not a
 * replay of the whole log. Open canvases see the epoch change and reload.
 */
class Moderation
{
    public function __construct(private readonly ConnectionInterface $db, private readonly Canvas $canvas)
    {
    }

    /**
     * @param array{x1: int, y1: int, x2: int, y2: int, userId: ?int, from: ?Carbon, to: ?Carbon} $filters
     */
    public function preview(Season $season, array $filters): array
    {
        $query = $this->matching($season, $filters);

        return [
            'tiles' => (clone $query)->count(),
            'painters' => (clone $query)->distinct()->count('user_id'),
        ];
    }

    /** @param array{x1: int, y1: int, x2: int, y2: int, userId: ?int, from: ?Carbon, to: ?Carbon} $filters */
    public function rollback(Season $season, User $actor, array $filters): Rollback
    {
        return $this->db->transaction(function () use ($season, $actor, $filters) {
            $season = $this->lock($season);

            $rollback = new Rollback();
            $rollback->season_id = $season->id;
            $rollback->actor_id = $actor->id;
            $rollback->filters = (string) json_encode([
                'x1' => $filters['x1'], 'y1' => $filters['y1'], 'x2' => $filters['x2'], 'y2' => $filters['y2'],
                'userId' => $filters['userId'],
                'from' => $filters['from']?->toIso8601String(),
                'to' => $filters['to']?->toIso8601String(),
            ]);
            $rollback->created_at = Carbon::now();
            $rollback->save();

            $users = (clone $this->matching($season, $filters))->distinct()->pluck('user_id')->all();
            $rollback->tiles = $this->matching($season, $filters)->update(['rollback_id' => $rollback->id]);
            $rollback->save();

            $area = [$filters['x1'], $filters['y1'], $filters['x2'], $filters['y2']];
            $this->canvas->rebuildArea($season, $area, -$rollback->tiles);
            $this->canvas->recountPainters($season, $users);

            return $rollback;
        });
    }

    public function undo(Rollback $rollback): void
    {
        $this->db->transaction(function () use ($rollback) {
            $season = $this->lock(Season::query()->findOrFail($rollback->season_id, Season::LIGHT));
            $users = Placement::query()->where('rollback_id', $rollback->id)->distinct()->pluck('user_id')->all();
            $restored = Placement::query()->where('rollback_id', $rollback->id)->update(['rollback_id' => null]);
            Rollback::query()->whereKey($rollback->id)->update(['undone_at' => Carbon::now()]);

            $f = json_decode($rollback->filters, true) ?: [];
            $area = [(int) ($f['x1'] ?? 0), (int) ($f['y1'] ?? 0), (int) ($f['x2'] ?? $season->width - 1), (int) ($f['y2'] ?? $season->height - 1)];
            $this->canvas->rebuildArea($season, $area, $restored);
            $this->canvas->recountPainters($season, $users);
        });
    }

    private function lock(Season $season): Season
    {
        $locked = Season::query()->whereKey($season->id)->lockForUpdate()->first(Season::LIGHT);
        if (! $locked || ! $locked->isOpen()) {
            throw new MuralException('mural_season_over', 409);
        }

        return $locked;
    }

    /** @param array{x1: int, y1: int, x2: int, y2: int, userId: ?int, from: ?Carbon, to: ?Carbon} $filters */
    private function matching(Season $season, array $filters): Builder
    {
        return Placement::live($season->id)
            ->whereBetween('x', [$filters['x1'], $filters['x2']])
            ->whereBetween('y', [$filters['y1'], $filters['y2']])
            ->when($filters['userId'], fn (Builder $q, int $id) => $q->where('user_id', $id))
            ->when($filters['from'], fn (Builder $q, Carbon $from) => $q->where('created_at', '>=', $from))
            ->when($filters['to'], fn (Builder $q, Carbon $to) => $q->where('created_at', '<=', $to));
    }
}
