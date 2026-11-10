<?php

namespace ErnestDefoe\Mural\Api;

use Carbon\Carbon;
use ErnestDefoe\Mural\Ban;
use ErnestDefoe\Mural\Presenter;
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
 * Moderators only:
 *   GET    /api/mural/bans               bans in force
 *   POST   /api/mural/bans               {username, days?, reason?}  (no days = until lifted)
 *   DELETE /api/mural/bans/{id}          lift one
 * A moderator can't ban another moderator unless they are an admin.
 */
class BansController implements RequestHandlerInterface
{
    public function __construct(private readonly Presenter $presenter)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(Settings::MODERATE);

        if ($request->getMethod() === 'DELETE') {
            Ban::query()->whereKey((int) Arr::get($request->getQueryParams(), 'id'))->delete();

            return new EmptyResponse(204);
        }

        if ($request->getMethod() === 'POST') {
            $body = (array) $request->getParsedBody();
            $user = User::query()->where('username', trim((string) Arr::get($body, 'username', '')))->first();
            if (! $user) {
                return Errors::make(422, 'mural_no_such_user');
            }
            if ($user->id === $actor->id || ($user->can(Settings::MODERATE) && ! $actor->isAdmin())) {
                return Errors::make(403, 'mural_cannot_ban');
            }
            $days = filter_var(Arr::get($body, 'days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3650]]);

            $ban = Ban::query()->find($user->id) ?? new Ban();
            $ban->user_id = $user->id;
            $ban->banned_by = $actor->id;
            $ban->reason = mb_substr(trim((string) Arr::get($body, 'reason', '')), 0, 200) ?: null;
            $ban->until = $days ? Carbon::now()->addDays($days) : null;
            $ban->created_at = Carbon::now();
            $ban->save();
        }

        $bans = Ban::active()->orderByDesc('created_at')->limit(100)->with('user')->get();

        return new JsonResponse(['data' => $bans->filter(fn (Ban $b) => $b->user !== null)->map(fn (Ban $b) => [
            'user' => $this->presenter->user($b->user),
            'reason' => $b->reason,
            'until' => $b->until?->toIso8601String(),
            'createdAt' => $b->created_at->toIso8601String(),
        ])->values()->all()]);
    }
}
