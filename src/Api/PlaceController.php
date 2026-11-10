<?php

namespace ErnestDefoe\Mural\Api;

use ErnestDefoe\Mural\Canvas;
use ErnestDefoe\Mural\MuralException;
use ErnestDefoe\Mural\Settings;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/mural/tiles {x, y, color}: place one tile. The cooldown, the
 * ban and the season being open are all decided here (Canvas::place);
 * the browser's timer is only a courtesy.
 */
class PlaceController implements RequestHandlerInterface
{
    use Seasons;

    /** Even with no cooldown set, nobody places more than this a minute (a script would hold the season's lock). */
    private const PER_MINUTE = 60;

    public function __construct(private readonly Canvas $canvas, private readonly RateLimit $limit)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        // Before the permission: core denies everything to an unconfirmed account, and this says why.
        if (! $actor->is_email_confirmed) {
            return Errors::make(403, 'mural_confirm_email');
        }
        // Checked on every tile: a suspended member stops at once.
        $actor->assertCan(Settings::PAINT);

        $body = (array) $request->getParsedBody();
        $x = self::int($body, 'x');
        $y = self::int($body, 'y');
        $color = self::int($body, 'color');
        if ($x === null || $y === null || $color === null) {
            return Errors::make(422, 'mural_bad_tile');
        }

        if ($this->limit->exceeded($request, $actor, 'place', self::PER_MINUTE)) {
            return Errors::make(429, 'mural_slow_down');
        }

        try {
            $result = $this->canvas->place($actor, $x, $y, $color);
        } catch (MuralException $e) {
            return Errors::from($e);
        }

        return new JsonResponse([
            'id' => $result['placement']->id,
            'season' => $result['season']->id,
            'nextAt' => $result['nextAt']->toIso8601String(),
            'now' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }
}
