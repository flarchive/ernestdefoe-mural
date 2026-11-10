<?php

namespace ErnestDefoe\Mural\Http;

use ErnestDefoe\Mural\Access;
use ErnestDefoe\Mural\Archive;
use ErnestDefoe\Mural\Season;
use ErnestDefoe\Mural\Settings;
use Flarum\Frontend\Document;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /mural, /mural/seasons and /mural/seasons/{n} as the server sends them:
 * a title, a canonical URL, the picture as the link preview, and noindex
 * unless the admin allows indexing. The canvas itself needs JavaScript.
 */
class PageContent
{
    public function __construct(
        private readonly UrlGenerator $url,
        private readonly TranslatorInterface $translator,
        private readonly SettingsRepositoryInterface $settings,
        private readonly Archive $archive,
    ) {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): Document
    {
        $mural = (string) $this->translator->trans('ernestdefoe-mural.forum.page.title');
        $number = (int) Arr::get($request->getQueryParams(), 'number');
        $forum = $this->url->to('forum');
        $image = $this->url->to('api')->path('mural/preview');

        if (! Access::canView(RequestUtil::getActor($request))) {
            $document->title = $mural;
        } elseif ($number && ($season = Season::query()->where('number', $number)->whereNotNull('ended_at')->first(Season::LIGHT))) {
            $name = (string) $this->translator->trans('ernestdefoe-mural.forum.season.name', ['number' => $season->number]);
            $document->title = ($season->title ? $season->title.' · ' : '').$name.' · '.$mural;
            $document->canonicalUrl = $forum->route('mural.season', ['number' => $season->number]);
            $image = $this->archive->url($season->image_path) ?? $image.'?season='.$season->number;
        } elseif (str_contains($request->getUri()->getPath(), '/seasons')) {
            $document->title = $this->translator->trans('ernestdefoe-mural.forum.gallery.title').' · '.$mural;
            $document->canonicalUrl = $forum->route('mural.seasons');
        } else {
            $document->title = $mural;
            $document->canonicalUrl = $forum->route('mural');
            $document->meta['description'] = (string) $this->translator->trans('ernestdefoe-mural.forum.page.description');
        }

        $document->head[] = '<meta property="og:image" content="'.e($image).'">';

        if (! $this->settings->get(Settings::INDEX)) {
            $document->meta['robots'] = 'noindex, nofollow';
        }

        return $document;
    }
}
