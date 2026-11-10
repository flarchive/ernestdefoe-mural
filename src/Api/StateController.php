<?php

namespace ErnestDefoe\Mural\Api;

use ErnestDefoe\Mural\Access;
use ErnestDefoe\Mural\Canvas;
use ErnestDefoe\Mural\Presenter;
use ErnestDefoe\Mural\Settings;
use ErnestDefoe\Mural\Stats;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/mural[?season=N]: a season's picture and what the viewer may do.
 *
 * The picture goes out as stored, compressed (raw deflate, base64; the
 * browser inflates it), plus the few placements made since it was last
 * folded. `upto` is the cursor the browser polls changes from; `epoch`
 * tells it when history was rewritten.
 */
class StateController implements RequestHandlerInterface
{
    use Seasons;

    public function __construct(private readonly Canvas $canvas, private readonly Presenter $presenter, private readonly Stats $stats)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        Access::assertView($actor);

        $season = $this->season($request, $this->canvas, true);
        if (! $season) {
            return Errors::make(404, 'mural_not_found');
        }

        $tail = $this->canvas->tail($season);
        $flat = [];
        foreach ($tail as [, $x, $y, $c]) {
            array_push($flat, $x, $y, $c);
        }
        $data = [
            'season' => $this->presenter->season($season),
            // A browser with no DecompressionStream (Safari before 16.4) asks for the bytes as they are.
            'snapshot' => ($raw = isset($request->getQueryParams()['raw'])) ? base64_encode($season->pixelsFromSnapshot()) : $season->snapshot,
            'raw' => $raw,
            'tail' => $flat,
            'upto' => $tail ? end($tail)[0] : $season->snapshot_upto,
            'epoch' => $season->epoch,
            'live' => $season->isOpen(),
            'canModerate' => $actor->can(Settings::MODERATE),
            // Whether tiles are pushed (realtime + a real queue + a public mural); otherwise the canvas keeps polling.
            'push' => $season->isOpen() && $this->canvas->realtime(),
        ];
        if ($season->isOpen() && ! $actor->isGuest()) {
            $ban = $this->canvas->banFor($actor);
            $data['me'] = [
                'canPaint' => $actor->can(Settings::PAINT) && $actor->is_email_confirmed && ! $ban,
                'cooldown' => $this->canvas->cooldownFor($actor),
                'banned' => $ban ? ['until' => $ban->until?->toIso8601String(), 'reason' => $ban->reason] : null,
                'confirmed' => (bool) $actor->is_email_confirmed,
            ] + $this->stats->mine($season, $actor);
        }

        return new JsonResponse(['data' => $data, 'now' => gmdate('Y-m-d\TH:i:s\Z')], 200, ['Cache-Control' => 'no-store']);
    }
}
