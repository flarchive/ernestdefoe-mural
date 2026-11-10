<?php

namespace ErnestDefoe\Mural\Api;

use ErnestDefoe\Mural\Access;
use ErnestDefoe\Mural\Canvas;
use ErnestDefoe\Mural\Stats;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** GET /api/mural/stats[?season=N]: tiles, painters, the last 24 hours and the top painters. */
class StatsController implements RequestHandlerInterface
{
    use Seasons;

    public function __construct(private readonly Canvas $canvas, private readonly Stats $stats)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        Access::assertView($actor);
        $season = $this->season($request, $this->canvas);
        if (! $season) {
            return Errors::make(404, 'mural_not_found');
        }

        return new JsonResponse(['data' => $this->stats->for($season, $actor)]);
    }
}
