<?php

/*
 * Mural: one shared pixel canvas the whole forum paints together.
 *
 * A season is one canvas. Members place one tile per cooldown (Canvas::place
 * decides it on the server, under the season's row lock); every tile goes
 * in an append-only log, which plays back as the timelapse and lets a
 * moderator roll an area back. Open canvases follow along through
 * flarum/realtime when it is there, polling a cached cursor when not.
 * Ending a season saves its picture and timelapse to the gallery.
 *
 * forum.js only wires routes, the nav link and the sidebar picture; the
 * canvas, gallery and tools are one lazy chunk.
 */

use ErnestDefoe\Mural\Api;
use ErnestDefoe\Mural\Gdpr\MuralData;
use ErnestDefoe\Mural\Http\PageContent;
use Flarum\Api\Context;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\Gdpr\Extend\UserData;
use Flarum\User\Event\Deleted;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // 🚨 Not optional: the pages are chunks in here, and undeclared chunks 404.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        ->route('/mural', 'mural', PageContent::class)
        ->route('/mural/seasons', 'mural.seasons', PageContent::class)
        ->route('/mural/seasons/{number:\d+}', 'mural.season', PageContent::class),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Routes('api'))
        ->get('/mural', 'mural.state', Api\StateController::class)
        ->get('/mural/changes', 'mural.changes', Api\ChangesController::class)
        ->post('/mural/tiles', 'mural.place', Api\PlaceController::class)
        ->get('/mural/tiles', 'mural.tile', Api\TileController::class)
        ->get('/mural/stats', 'mural.stats', Api\StatsController::class)
        ->get('/mural/preview', 'mural.preview', Api\PreviewController::class)
        ->get('/mural/seasons', 'mural.seasons.index', Api\SeasonsController::class)
        ->post('/mural/seasons', 'mural.seasons.end', Api\EndSeasonController::class)
        ->get('/mural/rollbacks', 'mural.rollbacks.index', Api\RollbacksController::class)
        ->post('/mural/rollbacks', 'mural.rollbacks.create', Api\RollbacksController::class)
        ->delete('/mural/rollbacks/{id:\d+}', 'mural.rollbacks.undo', Api\RollbacksController::class)
        ->get('/mural/bans', 'mural.bans.index', Api\BansController::class)
        ->post('/mural/bans', 'mural.bans.create', Api\BansController::class)
        ->delete('/mural/bans/{id:\d+}', 'mural.bans.delete', Api\BansController::class),

    // The viewer's own permissions: already loaded, so no query.
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('muralCanView')
                ->get(fn ($forum, Context $context) => \ErnestDefoe\Mural\Access::canView($context->getActor())),
        ]),

    // 🚨 String literals, not Settings:: constants: extend.php runs while a deploy
    // swaps vendor/, and a class constant read here fatals every request then.
    (new Extend\Settings())
        ->default('ernestdefoe-mural.nav', true)
        ->default('ernestdefoe-mural.sidebar_widget', true)
        ->default('ernestdefoe-mural.cooldown', 300)
        ->default('ernestdefoe-mural.width', 128)
        ->default('ernestdefoe-mural.height', 128)
        ->default('ernestdefoe-mural.palette', 'mural32')
        ->serializeToForum('muralNav', 'ernestdefoe-mural.nav', 'boolval')
        ->serializeToForum('muralWidget', 'ernestdefoe-mural.sidebar_widget', 'boolval'),

    // The foreign keys do this too, where the database enforces them (SQLite may not).
    (new Extend\Event())
        ->listen(Deleted::class, function (Deleted $event) {
            \ErnestDefoe\Mural\Placement::query()->where('user_id', $event->user->id)->update(['user_id' => null]);
            \ErnestDefoe\Mural\Ban::query()->where('user_id', $event->user->id)->delete();
            resolve('db')->table('mural_painters')->where('user_id', $event->user->id)->delete();
        }),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new UserData())->addType(MuralData::class),
        ]),
];
