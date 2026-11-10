<?php

namespace ErnestDefoe\Mural\Api;

use ErnestDefoe\Mural\Access;
use ErnestDefoe\Mural\Archive;
use ErnestDefoe\Mural\Canvas;
use ErnestDefoe\Mural\Palette;
use ErnestDefoe\Mural\Png;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Repository as Cache;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 🚨 No ".png" on the route: nginx serves any URL ending in an image extension as
 * a static file, so it never reaches PHP on a real server (Herd hides this).
 *
 * GET /api/mural/preview[?season=N]: the canvas as a picture, for the
 * sidebar widget and for link previews. Encoded at most every 20 seconds
 * whatever the traffic (the PNG is cached with the head it was drawn at);
 * a finished season redirects to its saved file.
 */
class PreviewController implements RequestHandlerInterface
{
    use Seasons;

    private const FRESH = 20;

    public function __construct(private readonly Canvas $canvas, private readonly Cache $cache, private readonly Archive $archive)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        Access::assertView(RequestUtil::getActor($request));
        $season = $this->season($request, $this->canvas);
        if (! $season) {
            return Errors::make(404, 'mural_not_found');
        }
        if (! $season->isOpen() && ($url = $this->archive->url($this->archive->ensure($season)->image_path))) {
            return new RedirectResponse($url, 302, ['Cache-Control' => (Access::isPublic() ? 'public' : 'private').', max-age=86400']);
        }

        $key = 'ernestdefoe-mural.preview.'.$season->id;
        $png = $this->cache->get($key);
        if (! is_string($png)) {
            $scale = max(1, intdiv(384, max($season->width, $season->height)));
            $png = Png::encode($season->width, $season->height, Palette::rgb($season->colours()), $this->canvas->pixels($season), $scale);
            $this->cache->put($key, $png, self::FRESH);
        }

        $body = new Stream('php://temp', 'w+b');
        $body->write($png);

        return (new Response($body, 200, [
            'Content-Type' => 'image/png',
            'Content-Length' => (string) strlen($png),
            // A forum closed to guests: no shared cache may keep the picture.
            'Cache-Control' => (Access::isPublic() ? 'public' : 'private').', max-age='.self::FRESH,
        ]));
    }
}
