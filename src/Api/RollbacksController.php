<?php

namespace ErnestDefoe\Mural\Api;

use Carbon\Carbon;
use ErnestDefoe\Mural\Canvas;
use ErnestDefoe\Mural\Moderation;
use ErnestDefoe\Mural\MuralException;
use ErnestDefoe\Mural\Presenter;
use ErnestDefoe\Mural\Rollback;
use ErnestDefoe\Mural\Settings;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Moderators only, on the open season:
 *   GET    /api/mural/rollbacks           the last 20, newest first
 *   POST   /api/mural/rollbacks           {x1, y1, x2, y2, username?, from?, to?, preview?}
 *   DELETE /api/mural/rollbacks/{id}      undo one
 * A rollback needs an area, and a member or a time range: "everything
 * everyone ever did here" is a preview away, never a slip of the mouse.
 */
class RollbacksController implements RequestHandlerInterface
{
    use Seasons;

    public function __construct(private readonly Canvas $canvas, private readonly Moderation $moderation, private readonly Presenter $presenter)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(Settings::MODERATE);
        $season = $this->canvas->current();

        try {
            return match ($request->getMethod()) {
                'POST' => $this->create($request, $actor, $season),
                'DELETE' => $this->undo($request, $season->id),
                default => $this->index($season->id),
            };
        } catch (MuralException $e) {
            return Errors::from($e);
        }
    }

    private function index(int $seasonId): JsonResponse
    {
        $rollbacks = Rollback::query()->where('season_id', $seasonId)->orderByDesc('id')->limit(20)->with('actor')->get();
        $targets = User::query()->whereIn('id', $rollbacks->map(fn (Rollback $r) => json_decode($r->filters, true)['userId'] ?? null)->filter()->unique())->get()->keyBy('id');

        return new JsonResponse(['data' => $rollbacks->map(function (Rollback $r) use ($targets) {
            $filters = json_decode($r->filters, true) ?: [];

            return [
                'id' => $r->id,
                'tiles' => $r->tiles,
                'createdAt' => $r->created_at->toIso8601String(),
                'undoneAt' => $r->undone_at?->toIso8601String(),
                'actor' => $this->presenter->user($r->actor),
                'target' => $this->presenter->user($targets->get($filters['userId'] ?? 0)),
                'filters' => $filters,
            ];
        })->all()]);
    }

    private function create(ServerRequestInterface $request, User $actor, \ErnestDefoe\Mural\Season $season): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $clamp = fn (?int $v, int $max) => max(0, min($max, $v ?? 0));
        $x = [$clamp(self::int($body, 'x1'), $season->width - 1), $clamp(self::int($body, 'x2'), $season->width - 1)];
        $y = [$clamp(self::int($body, 'y1'), $season->height - 1), $clamp(self::int($body, 'y2'), $season->height - 1)];

        $userId = null;
        $username = trim((string) Arr::get($body, 'username', ''));
        if ($username !== '') {
            $userId = User::query()->where('username', $username)->value('id');
            if (! $userId) {
                return Errors::make(422, 'mural_no_such_user');
            }
        }
        $from = $this->date(Arr::get($body, 'from'));
        $to = $this->date(Arr::get($body, 'to'));
        if (! $userId && ! $from && ! $to) {
            return Errors::make(422, 'mural_rollback_needs_filter');
        }

        $filters = ['x1' => min($x), 'x2' => max($x), 'y1' => min($y), 'y2' => max($y), 'userId' => $userId ? (int) $userId : null, 'from' => $from, 'to' => $to];

        if (filter_var(Arr::get($body, 'preview', false), FILTER_VALIDATE_BOOLEAN)) {
            return new JsonResponse(['data' => $this->moderation->preview($season, $filters)]);
        }

        $rollback = $this->moderation->rollback($season, $actor, $filters);

        return new JsonResponse(['data' => ['id' => $rollback->id, 'tiles' => $rollback->tiles]], 201);
    }

    private function undo(ServerRequestInterface $request, int $seasonId): ResponseInterface
    {
        $rollback = Rollback::query()->where('season_id', $seasonId)->whereNull('undone_at')->find((int) Arr::get($request->getQueryParams(), 'id'));
        if (! $rollback) {
            return Errors::make(404, 'mural_not_found');
        }
        $this->moderation->undo($rollback);

        return new EmptyResponse(204);
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
