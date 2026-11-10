<?php

namespace ErnestDefoe\Mural\Api;

use ErnestDefoe\Mural\Access;
use ErnestDefoe\Mural\Canvas;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/mural/changes?s={season id}&since={id}&epoch={n}: tiles placed
 * after the cursor, flat [x, y, colour, …], or `reset` when the browser must
 * reload (history was rewritten, or the season ended).
 *
 * What open canvases poll when realtime is not there: a quiet canvas
 * answers from two cached values and reads no table. The cursor is the
 * placement id, never a clock (ids commit in order: see Canvas).
 */
class ChangesController implements RequestHandlerInterface
{
    use Seasons;

    private const LIMIT = 2000;

    public function __construct(private readonly Canvas $canvas)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // A permission check only: no query.
        if (! Access::canView(RequestUtil::getActor($request))) {
            return Errors::make(403, 'permission_denied');
        }

        $query = $request->getQueryParams();
        $seasonId = self::int($query, 's', 0);
        $since = self::int($query, 'since', 0);
        $epoch = self::int($query, 'epoch', 0);

        if ($seasonId !== $this->canvas->currentId()) {
            return $this->answer(['reset' => true]);
        }

        [$headEpoch, $headId] = array_map('intval', explode(':', $this->canvas->head($seasonId)) + [0, 0]);

        if ($headEpoch !== $epoch) {
            return $this->answer(['reset' => true]);
        }
        if ($since >= $headId) {
            return $this->answer(['upto' => $since, 'p' => []]);
        }

        $rows = $this->canvas->tailAfter($seasonId, $since, self::LIMIT);
        $flat = [];
        foreach ($rows as [, $x, $y, $c]) {
            array_push($flat, $x, $y, $c);
        }

        return $this->answer([
            'upto' => $rows ? end($rows)[0] : $headId,
            'p' => $flat,
            'more' => count($rows) === self::LIMIT,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function answer(array $data): JsonResponse
    {
        return new JsonResponse($data, 200, ['Cache-Control' => 'no-store']);
    }
}
