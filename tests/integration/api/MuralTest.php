<?php

namespace ErnestDefoe\Mural\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Mural\Canvas;
use ErnestDefoe\Mural\Placement;
use ErnestDefoe\Mural\Season;
use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class MuralTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-mural');
        $this->setting('ernestdefoe-mural.width', 32);
        $this->setting('ernestdefoe-mural.height', 24);
        $this->setting('ernestdefoe-mural.cooldown', 300);

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'dana', 'email' => 'dana@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'nomail', 'email' => 'nomail@machine.local', 'is_email_confirmed' => 0],
                ['id' => 5, 'username' => 'mod', 'email' => 'mod@machine.local', 'is_email_confirmed' => 1],
            ],
            'group_user' => [
                ['user_id' => 5, 'group_id' => Group::MODERATOR_ID],
            ],
        ]);
    }

    /** @return array{int, array<string, mixed>|null} */
    private function api(string $method, string $path, ?int $as, array $body = [], array $query = []): array
    {
        $options = $as ? ['authenticatedAs' => $as] : [];
        if ($method !== 'GET') {
            $options['json'] = $body;
        }
        $request = $this->request($method, '/api/mural'.$path, $options);
        if ($query) {
            $request = $request->withQueryParams($query);
        }
        $response = $this->send($request);

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    private function place(int $as, int $x, int $y, int $color): array
    {
        return $this->api('POST', '/tiles', $as, ['x' => $x, 'y' => $y, 'color' => $color]);
    }

    private function ready(int $userId): void
    {
        $this->database()->table('mural_painters')->where('user_id', $userId)->update(['next_at' => null]);
    }

    private function code(?array $body): ?string
    {
        return $body['errors'][0]['code'] ?? null;
    }

    #[Test]
    public function guests_see_the_canvas_and_a_season_starts_itself(): void
    {
        [$status, $body] = $this->api('GET', '', null);

        $this->assertSame(200, $status);
        $this->assertSame(32, $body['data']['season']['width']);
        $this->assertSame(24, $body['data']['season']['height']);
        $this->assertSame(1, $body['data']['season']['number']);
        $this->assertArrayNotHasKey('me', $body['data']);
        $pixels = gzinflate(base64_decode($body['data']['snapshot']));
        $this->assertSame(32 * 24, strlen($pixels));
    }

    #[Test]
    public function a_member_places_a_tile_then_waits(): void
    {
        [$status, $body] = $this->place(2, 3, 4, 6);
        $this->assertSame(200, $status, json_encode($body));

        [$status, $body] = $this->place(2, 5, 5, 7);
        $this->assertSame(429, $status);
        $this->assertSame('mural_cooldown', $this->code($body));
        $this->assertNotEmpty($body['errors'][0]['meta']['nextAt']);

        [, $state] = $this->api('GET', '', 2);
        $this->assertSame([3, 4, 6], $state['data']['tail']);
        $this->assertSame(1, $state['data']['me']['tiles']);
        $this->assertSame(1, $state['data']['me']['rank']);
        $this->assertNotNull($state['data']['me']['nextAt']);

        // Another member is not held up by someone else's cooldown.
        $this->assertSame(200, $this->place(3, 3, 4, 9)[0]);
        [, $tile] = $this->api('GET', '/tiles', null, [], ['x' => 3, 'y' => 4]);
        $this->assertSame('dana', $tile['data']['user']['username']);
        $this->assertSame(9, $tile['data']['color']);
        $this->assertSame(2, $tile['data']['count']);
    }

    #[Test]
    public function a_group_can_wait_less(): void
    {
        $this->setting('ernestdefoe-mural.group_cooldowns', json_encode([(string) Group::MODERATOR_ID => 0]));

        $this->assertSame(200, $this->place(5, 1, 1, 2)[0]);
        $this->assertSame(200, $this->place(5, 2, 1, 2)[0]);
        // A plain member still waits.
        $this->assertSame(200, $this->place(2, 1, 1, 2)[0]);
        $this->assertSame(429, $this->place(2, 2, 2, 2)[0]);
    }

    #[Test]
    public function bad_tiles_guests_and_unconfirmed_members_are_turned_away(): void
    {
        $this->assertSame('mural_bad_tile', $this->code($this->place(2, 32, 0, 1)[1]));
        $this->assertSame('mural_bad_tile', $this->code($this->place(2, 0, 24, 1)[1]));
        $this->assertSame('mural_bad_tile', $this->code($this->place(2, 0, 0, 32)[1]));
        $this->assertSame('mural_bad_tile', $this->code($this->place(2, -1, 0, 1)[1]));
        // A guest is stopped before the controller (CSRF) or by it (401): either way, no tile.
        $this->assertContains($this->api('POST', '/tiles', null, ['x' => 1, 'y' => 1, 'color' => 1])[0], [400, 401]);
        $this->assertSame('mural_confirm_email', $this->code($this->place(4, 1, 1, 1)[1]));
        $this->assertSame(0, Placement::query()->count());
    }

    #[Test]
    public function a_banned_member_cannot_paint_and_only_moderators_ban(): void
    {
        $this->assertSame(403, $this->api('POST', '/bans', 2, ['username' => 'dana'])[0]);

        [$status] = $this->api('POST', '/bans', 5, ['username' => 'dana', 'days' => 2, 'reason' => 'spam']);
        $this->assertSame(200, $status);

        [$status, $body] = $this->place(3, 1, 1, 1);
        $this->assertSame(403, $status);
        $this->assertSame('mural_banned', $this->code($body));

        // A moderator can't ban another moderator unless they are an admin.
        $this->assertSame('mural_cannot_ban', $this->code($this->api('POST', '/bans', 5, ['username' => 'mod'])[1]));

        $this->assertSame(204, $this->api('DELETE', '/bans/3', 5)[0]);
        $this->assertSame(200, $this->place(3, 1, 1, 1)[0]);
    }

    #[Test]
    public function changes_come_in_order_after_the_cursor(): void
    {
        [, $state] = $this->api('GET', '', null);
        $season = $state['data']['season']['id'];
        $cursor = $state['data']['upto'];

        [, $none] = $this->api('GET', '/changes', null, [], ['s' => $season, 'since' => $cursor, 'epoch' => 0]);
        $this->assertSame([], $none['p']);

        $this->place(2, 1, 2, 3);
        $this->place(3, 4, 5, 6);

        [, $diff] = $this->api('GET', '/changes', null, [], ['s' => $season, 'since' => $cursor, 'epoch' => 0]);
        $this->assertSame([1, 2, 3, 4, 5, 6], $diff['p']);
        $this->assertGreaterThan($cursor, $diff['upto']);

        // A stale epoch or another season: reload.
        $this->assertTrue($this->api('GET', '/changes', null, [], ['s' => $season, 'since' => 0, 'epoch' => 9])[1]['reset']);
        $this->assertTrue($this->api('GET', '/changes', null, [], ['s' => $season + 1, 'since' => 0, 'epoch' => 0])[1]['reset']);
    }

    #[Test]
    public function the_snapshot_folds_the_tail_in(): void
    {
        $canvas = $this->app()->getContainer()->make(Canvas::class);
        $season = $canvas->current();
        $rows = [];
        for ($i = 0; $i < Canvas::FOLD_AT + 3; $i++) {
            $rows[] = ['season_id' => $season->id, 'user_id' => 3, 'x' => $i % 32, 'y' => intdiv($i, 32), 'color' => 5, 'created_at' => Carbon::now()];
        }
        Placement::query()->insert($rows);

        // The next real placement folds everything before it.
        $this->assertSame(200, $this->place(2, 31, 23, 7)[0]);
        $season = Season::query()->findOrFail($season->id);
        $this->assertSame((int) Placement::query()->max('id'), $season->snapshot_upto);

        $pixels = $canvas->pixels($season);
        $this->assertSame(5, ord($pixels[0]));
        $this->assertSame(7, ord($pixels[23 * 32 + 31]));
    }

    #[Test]
    public function a_rollback_restores_the_area_and_can_be_undone(): void
    {
        $this->place(3, 2, 2, 4);
        $this->ready(3);
        $this->place(2, 2, 2, 9);
        $this->place(3, 10, 10, 8);
        $this->ready(3);

        // Members can't; a moderator must say whose tiles or when.
        $this->assertSame(403, $this->api('POST', '/rollbacks', 2, ['x1' => 0, 'y1' => 0, 'x2' => 5, 'y2' => 5, 'username' => 'normal'])[0]);
        $this->assertSame('mural_rollback_needs_filter', $this->code($this->api('POST', '/rollbacks', 5, ['x1' => 0, 'y1' => 0, 'x2' => 5, 'y2' => 5])[1]));

        [, $preview] = $this->api('POST', '/rollbacks', 5, ['x1' => 0, 'y1' => 0, 'x2' => 5, 'y2' => 5, 'username' => 'normal', 'preview' => true]);
        $this->assertSame(['tiles' => 1, 'painters' => 1], $preview['data']);

        [$status, $done] = $this->api('POST', '/rollbacks', 5, ['x1' => 0, 'y1' => 0, 'x2' => 5, 'y2' => 5, 'username' => 'normal']);
        $this->assertSame(201, $status);

        $canvas = $this->app()->getContainer()->make(Canvas::class);
        $season = $canvas->current(true);
        $this->assertSame(1, $season->epoch);
        $pixels = $canvas->pixels($season);
        $this->assertSame(4, ord($pixels[2 * 32 + 2]), "dana's tile shows again");
        $this->assertSame(8, ord($pixels[10 * 32 + 10]), 'outside the area is untouched');
        $this->assertSame(0, (int) $this->database()->table('mural_painters')->where('user_id', 2)->value('tiles'));

        $this->assertSame(204, $this->api('DELETE', '/rollbacks/'.$done['data']['id'], 5)[0]);
        $pixels = $canvas->pixels($canvas->current(true));
        $this->assertSame(9, ord($pixels[2 * 32 + 2]));
        $this->assertSame(1, (int) $this->database()->table('mural_painters')->where('user_id', 2)->value('tiles'));
    }

    #[Test]
    public function ending_a_season_saves_it_and_opens_the_next(): void
    {
        $this->place(2, 0, 0, 6);
        $this->place(3, 1, 0, 7);

        $this->assertSame(403, $this->api('POST', '/seasons', 2)[0]);
        [$status, $next] = $this->api('POST', '/seasons', 5, ['title' => 'Round two', 'width' => 20, 'height' => 20, 'palette' => 'mural16']);
        $this->assertSame(201, $status, json_encode($next));
        $this->assertSame(2, $next['data']['number']);
        $this->assertSame('Round two', $next['data']['title']);
        $this->assertCount(16, $next['data']['palette']);

        [, $gallery] = $this->api('GET', '/seasons', null);
        $this->assertCount(1, $gallery['data']);
        $old = $gallery['data'][0];
        $this->assertStringEndsWith('.png', $old['imageUrl']);
        $this->assertStringEndsWith('.bin', $old['timelapseUrl']);
        $this->assertEqualsCanonicalizing(['normal', 'dana'], array_map(fn ($row) => $row['user']['username'], $old['top']));

        // The old season no longer takes tiles; the new one does.
        $this->ready(2);
        [, $state] = $this->api('GET', '', 2);
        $this->assertSame(2, $state['data']['season']['number']);
        $this->assertSame(200, $this->place(2, 19, 19, 15)[0]);
        $this->assertSame('mural_bad_tile', $this->code($this->place(3, 25, 0, 1)[1]));

        $season = Season::query()->where('number', 1)->firstOrFail();
        $disk = $this->app()->getContainer()->make(\Illuminate\Contracts\Filesystem\Factory::class)->disk('flarum-assets');
        $png = $disk->get($season->image_path);
        $this->assertStringStartsWith("\x89PNG", $png);
        $bin = $disk->get($season->timelapse_path);
        $this->assertSame('MRL1', substr($bin, 0, 4));
        $this->assertSame(8 + 2 * 5, strlen($bin));
        $disk->delete([$season->image_path, $season->timelapse_path]);
    }

    #[Test]
    public function stats_count_tiles_and_painters(): void
    {
        $this->place(2, 0, 0, 6);
        $this->place(3, 1, 0, 7);
        $this->ready(3);
        $this->place(3, 2, 0, 7);

        [, $stats] = $this->api('GET', '/stats', null);
        $this->assertSame(3, $stats['data']['tiles']);
        $this->assertSame(2, $stats['data']['painters']);
        $this->assertSame(3, $stats['data']['last24']);
        $this->assertSame('dana', $stats['data']['top'][0]['user']['username']);
        $this->assertSame(2, $stats['data']['top'][0]['tiles']);
    }

    #[Test]
    public function the_preview_is_a_png(): void
    {
        $this->place(2, 0, 0, 6);
        $response = $this->send($this->request('GET', '/api/mural/preview'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/png', $response->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith("\x89PNG", (string) $response->getBody());
    }

    #[Test]
    public function a_flushed_cache_after_a_rollback_does_not_send_canvases_into_a_reset_loop(): void
    {
        $this->place(2, 2, 2, 9);
        $this->api('POST', '/rollbacks', 5, ['x1' => 0, 'y1' => 0, 'x2' => 5, 'y2' => 5, 'username' => 'normal']);
        [, $state] = $this->api('GET', '', null);
        $this->assertSame(1, $state['data']['epoch']);

        $this->app()->getContainer()->make('cache.store')->flush();

        $query = ['s' => $state['data']['season']['id'], 'since' => $state['data']['upto'], 'epoch' => 1];
        [, $poll] = $this->api('GET', '/changes', null, [], $query);
        $this->assertArrayNotHasKey('reset', $poll, 'a cache miss must read the real epoch');
        [, $again] = $this->api('GET', '/changes', null, [], $query);
        $this->assertArrayNotHasKey('reset', $again);
    }

    #[Test]
    public function a_forum_closed_to_guests_keeps_the_mural_closed(): void
    {
        $this->place(2, 1, 1, 6);
        $this->database()->table('group_permission')->where('group_id', Group::GUEST_ID)->where('permission', 'viewForum')->delete();

        foreach (['', '/stats', '/seasons', '/preview'] as $path) {
            $this->assertContains($this->api('GET', $path, null)[0], [401, 403], "guest $path");
        }
        $this->assertContains($this->api('GET', '/tiles', null, [], ['x' => 1, 'y' => 1])[0], [401, 403]);
        $this->assertContains($this->api('GET', '/changes', null, [], ['s' => 1, 'since' => 0, 'epoch' => 0])[0], [401, 403]);

        // Members still see it.
        [$status, $tile] = $this->api('GET', '/tiles', 3, [], ['x' => 1, 'y' => 1]);
        $this->assertSame(200, $status);
        $this->assertSame('normal', $tile['data']['user']['username']);
    }

    #[Test]
    public function index_and_foreign_key_names_carry_the_table_prefix_and_fit(): void
    {
        $db = $this->database();
        $prefix = $db->getTablePrefix();
        $schema = $db->getSchemaBuilder();
        foreach (['mural_seasons', 'mural_placements', 'mural_painters', 'mural_rollbacks', 'mural_bans'] as $table) {
            $names = array_merge(
                array_column($schema->getIndexes($table), 'name'),
                array_filter(array_column($schema->getForeignKeys($table), 'name'))
            );
            foreach ($names as $name) {
                if (strtolower($name) === 'primary' || str_starts_with($name, 'sqlite_autoindex')) {
                    continue;
                }
                $this->assertStringStartsWith($prefix.'mural_', $name, "$table: $name");
                $this->assertLessThanOrEqual(64, strlen($name), $name);
            }
        }
    }

    #[Test]
    public function a_rollback_redraws_only_its_area_from_the_newest_live_tiles(): void
    {
        // Three layers on one tile, and a tile outside the area.
        $this->place(3, 4, 4, 1);
        $this->ready(3);
        $this->place(2, 4, 4, 2);
        $this->place(3, 4, 4, 3);
        $this->ready(3);
        $this->place(3, 20, 20, 7);
        $before = Season::query()->firstOrFail()->placements_count;

        $this->api('POST', '/rollbacks', 5, ['x1' => 4, 'y1' => 4, 'x2' => 4, 'y2' => 4, 'username' => 'dana']);

        $canvas = $this->app()->getContainer()->make(Canvas::class);
        $season = $canvas->current(true);
        $pixels = $canvas->pixels($season);
        $this->assertSame(2, ord($pixels[4 * 32 + 4]), "normal's tile under dana's two shows again");
        $this->assertSame(7, ord($pixels[20 * 32 + 20]));
        $this->assertSame($before - 2, $season->placements_count);
        $this->assertSame(1, (int) $this->database()->table('mural_painters')->where('user_id', 3)->value('tiles'));
    }
}
