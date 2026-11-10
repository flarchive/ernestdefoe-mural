<?php

namespace ErnestDefoe\Mural\Api;

use ErnestDefoe\Mural\Access;
use ErnestDefoe\Mural\Archive;
use ErnestDefoe\Mural\Canvas;
use ErnestDefoe\Mural\Presenter;
use ErnestDefoe\Mural\Season;
use ErnestDefoe\Mural\Stats;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/mural/seasons: the gallery, newest first, each with its saved
 * picture, timelapse and its top three painters (kept once a season ends:
 * after the first view, one query for all of their names).
 */
class SeasonsController implements RequestHandlerInterface
{
    use Seasons;

    private const PAGE = 24;

    public function __construct(private readonly Presenter $presenter, private readonly Archive $archive, private readonly Stats $stats, private readonly Canvas $canvas)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        Access::assertView($actor);
        $page = max(0, self::int($request->getQueryParams(), 'page', 0) ?? 0);

        $seasons = Season::query()->whereNotNull('ended_at')->orderByDesc('number')
            ->offset($page * self::PAGE)->limit(self::PAGE + 1)->get(Season::LIGHT);
        $more = $seasons->count() > self::PAGE;
        $seasons = $seasons->take(self::PAGE);

        // A season whose files failed to write gets them now (rare: the end request does it).
        $seasons = $seasons->map(fn (Season $s) => $this->archive->ensure($s));
        $leaders = $this->stats->leaders($seasons->pluck('id')->all(), 3, $actor);

        return new JsonResponse([
            'data' => $seasons->map(fn (Season $s) => $this->presenter->season($s) + ['top' => $leaders[$s->id] ?? []])->values()->all(),
            'current' => $this->presenter->season($this->canvas->current()),
            'more' => $more,
        ]);
    }
}
