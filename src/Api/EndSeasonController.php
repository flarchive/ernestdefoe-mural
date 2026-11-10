<?php

namespace ErnestDefoe\Mural\Api;

use ErnestDefoe\Mural\Archive;
use ErnestDefoe\Mural\MuralException;
use ErnestDefoe\Mural\Presenter;
use ErnestDefoe\Mural\Settings;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/mural/seasons {title?, width?, height?, palette?}: end the
 * open season (its picture and timelapse go to the gallery) and start the
 * next one. Size and palette default to the admin's settings.
 */
class EndSeasonController implements RequestHandlerInterface
{
    use Seasons;

    public function __construct(private readonly Archive $archive, private readonly Presenter $presenter)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(Settings::MODERATE);

        $body = (array) $request->getParsedBody();
        $palette = isset($body['palette']) && is_string($body['palette']) ? mb_substr($body['palette'], 0, 1000) : null;
        $title = isset($body['title']) && is_string($body['title']) ? $body['title'] : null;

        try {
            $next = $this->archive->end($actor, $title, self::int($body, 'width'), self::int($body, 'height'), $palette);
        } catch (MuralException $e) {
            return Errors::from($e);
        }

        return new JsonResponse(['data' => $this->presenter->season($next)], 201);
    }
}
