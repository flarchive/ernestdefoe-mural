<?php

namespace ErnestDefoe\Mural\Api;

use ErnestDefoe\Mural\Access;
use ErnestDefoe\Mural\Canvas;
use ErnestDefoe\Mural\Placement;
use ErnestDefoe\Mural\Presenter;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/mural/tiles?season=N&x=&y=: who placed the tile that is there
 * now, and when. One indexed lookup; the browser asks only after the
 * pointer rests on a tile, or on a tap.
 */
class TileController implements RequestHandlerInterface
{
    use Seasons;

    public function __construct(private readonly Canvas $canvas, private readonly Presenter $presenter, private readonly RateLimit $limit)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        Access::assertView($actor);
        if ($this->limit->exceeded($request, $actor, 'tile', 240)) {
            return Errors::make(429, 'mural_slow_down');
        }

        $season = $this->season($request, $this->canvas);
        $query = $request->getQueryParams();
        $x = self::int($query, 'x', -1);
        $y = self::int($query, 'y', -1);
        if (! $season || $x < 0 || $y < 0 || $x >= $season->width || $y >= $season->height) {
            return Errors::make(404, 'mural_not_found');
        }

        $placement = Placement::live($season->id)->where('y', $y)->where('x', $x)->orderByDesc('id')->first();
        // Only a member this viewer may see is named; otherwise the tile shows as painted by someone unnamed.
        $user = $placement?->user_id ? User::query()->whereVisibleTo($actor)->find($placement->user_id) : null;

        return new JsonResponse(['data' => $placement ? [
            'x' => $x,
            'y' => $y,
            'color' => $placement->color,
            'placedAt' => $placement->created_at->toIso8601String(),
            'user' => $this->presenter->user($user),
            'count' => Placement::live($season->id)->where('y', $y)->where('x', $x)->count(),
        ] : null]);
    }
}
