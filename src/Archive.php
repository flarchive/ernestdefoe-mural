<?php

namespace ErnestDefoe\Mural;

use Carbon\Carbon;
use Flarum\User\User;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Log\LoggerInterface;
use Illuminate\Contracts\Queue\Queue;
use ErnestDefoe\Mural\Job\ArchiveSeason;

/**
 * Ending a season: the canvas closes and the next one opens in the same
 * transaction (so nobody paints in between, and an open canvas that
 * reloads finds the new one), then a queued job writes the finished picture
 * and its timelapse to the assets disk, where the gallery serves them as
 * plain files: playing a timelapse costs the forum no PHP at all.
 *
 * Timelapse file: "MRL1", width and height (uint16 LE), then 5 bytes per
 * live placement in order: x, y (uint16 LE), colour index (uint8).
 */
class Archive
{
    private const RETRY_FAILED = 10;
    private const RETRY_STUCK = 30;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Canvas $canvas,
        private readonly Factory $filesystem,
        private readonly Cache $cache,
        private readonly LoggerInterface $logger,
        private readonly Queue $queue,
    ) {
    }

    public function end(User $actor, ?string $title, ?int $width, ?int $height, ?string $palette): Season
    {
        $this->canvas->current();
        [$ended, $next] = $this->db->transaction(function () use ($title, $width, $height, $palette) {
            $season = Season::query()->whereNull('ended_at')->orderByDesc('number')->lockForUpdate()->first(Season::LIGHT);
            if (! $season || $season->ended_at !== null) {
                throw new MuralException('mural_season_over', 409);
            }
            $season->ended_at = Carbon::now();
            $season->save();

            $next = $this->canvas->start($title, $width, $height, $palette, $season->number + 1)
                ?? throw new MuralException('mural_season_over', 409);

            return [$season, $next];
        });

        $this->canvas->forgetCurrent();
        $this->canvas->afterCommit(fn () => $this->canvas->broadcastReset($ended));
        $this->ensure($ended);

        return $next;
    }

    /**
     * Ask for an ended season's picture and timelapse if they are missing.
     * Never does the work itself: it claims the season (one conditional
     * UPDATE, so of many visitors only one wins) and queues ArchiveSeason.
     * A claim that failed, or was left "building" by a fatal timeout, may be
     * retried after a while; until then nobody starts it again.
     */
    public function ensure(Season $season): Season
    {
        if ($season->isOpen() || ($season->image_path && $season->timelapse_path)) {
            return $season;
        }
        $now = Carbon::now();
        $claimed = Season::query()->whereKey($season->id)->whereNotNull('ended_at')
            ->where(fn ($q) => $q->whereNull('archive_state')
                ->orWhere(fn ($q) => $q->where('archive_state', 'failed')->where('archive_at', '<', $now->copy()->subMinutes(self::RETRY_FAILED)))
                ->orWhere(fn ($q) => $q->where('archive_state', 'building')->where('archive_at', '<', $now->copy()->subMinutes(self::RETRY_STUCK))))
            ->update(['archive_state' => 'building', 'archive_at' => $now]);

        if ($claimed) {
            $season->archive_state = 'building';
            $this->queue->push(new ArchiveSeason($season->id));
        }

        return $season;
    }

    /** The job: write both files, under a lock, and record the outcome either way. */
    public function build(int $seasonId): void
    {
        $lock = $this->cache instanceof \Illuminate\Cache\Repository && $this->cache->getStore() instanceof \Illuminate\Contracts\Cache\LockProvider
            ? $this->cache->getStore()->lock('ernestdefoe-mural.archive.'.$seasonId, 600) : null;
        if ($lock && ! $lock->get()) {
            return;
        }

        try {
            $season = Season::query()->find($seasonId, Season::LIGHT);
            if ($season && ! $season->isOpen()) {
                $this->write($season);
                Season::query()->whereKey($seasonId)->update(['archive_state' => 'done', 'archive_at' => Carbon::now()]);
            }
        } catch (\Throwable $e) {
            Season::query()->whereKey($seasonId)->update(['archive_state' => 'failed', 'archive_at' => Carbon::now()]);
            $this->logger->error('[mural] could not save season '.$seasonId.': '.$e->getMessage());
        } finally {
            $lock?->release();
        }
    }

    private function write(Season $season): void
    {
        @set_time_limit(600);

        $disk = $this->disk();
        $stem = 'mural/season-'.$season->number.'-'.Str::lower(Str::random(8));
        $pixels = $this->canvas->pixels($season);
        $rgb = Palette::rgb($season->colours());

        $image = $stem.'.png';
        $disk->put($image, Png::encode($season->width, $season->height, $rgb, $pixels, $this->scale($season)));

        $timelapse = $stem.'.bin';
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, 'MRL1'.pack('vv', $season->width, $season->height));
        Placement::live($season->id)->toBase()->select(['id', 'x', 'y', 'color'])->orderBy('id')
            ->chunkById(5000, function ($rows) use ($stream) {
                $chunk = '';
                foreach ($rows as $p) {
                    $chunk .= pack('vvC', $p->x, $p->y, $p->color);
                }
                fwrite($stream, $chunk);
            });
        rewind($stream);
        $disk->writeStream($timelapse, $stream);
        fclose($stream);

        foreach ([$season->image_path, $season->timelapse_path] as $old) {
            if ($old) {
                $disk->delete($old);
            }
        }
        Season::query()->whereKey($season->id)->update(['image_path' => $image, 'timelapse_path' => $timelapse]);
    }

    /** The saved picture is scaled up so it stays crisp anywhere it is shown or shared (about 1024 px across). */
    public function scale(Season $season): int
    {
        return max(1, intdiv(1024, max($season->width, $season->height)));
    }

    public function url(?string $path): ?string
    {
        return $path ? $this->disk()->url($path) : null;
    }

    private function disk(): FilesystemAdapter
    {
        $disk = $this->filesystem->disk('flarum-assets');
        if (! $disk instanceof FilesystemAdapter) {
            throw new \RuntimeException('The flarum-assets disk cannot make URLs.');
        }

        return $disk;
    }
}
