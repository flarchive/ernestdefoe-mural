<?php

namespace ErnestDefoe\Mural;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property int $number
 * @property string|null $title
 * @property int $width
 * @property int $height
 * @property string $palette JSON list of hex colours
 * @property string $snapshot
 * @property int $snapshot_upto
 * @property int $epoch
 * @property int $placements_count
 * @property int $painters_count
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property string|null $image_path
 * @property string|null $timelapse_path
 * @property string|null $archive_state
 * @property Carbon|null $archive_at
 */
class Season extends AbstractModel
{
    protected $table = 'mural_seasons';

    public $timestamps = true;

    protected $casts = [
        'number' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'snapshot_upto' => 'integer',
        'epoch' => 'integer',
        'placements_count' => 'integer',
        'painters_count' => 'integer',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'archive_at' => 'datetime',
    ];

    /** The snapshot is ~a few KB to a few hundred: never loaded unless asked for. */
    public const LIGHT = ['id', 'number', 'title', 'width', 'height', 'palette', 'snapshot_upto', 'epoch', 'placements_count', 'painters_count', 'started_at', 'ended_at', 'image_path', 'timelapse_path', 'archive_state', 'archive_at'];

    /** @return list<string> */
    public function colours(): array
    {
        $colours = json_decode($this->palette, true);

        return is_array($colours) ? array_values($colours) : Palette::MURAL_32;
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }

    public static function encode(string $pixels): string
    {
        return base64_encode((string) gzdeflate($pixels, 6));
    }

    public function pixelsFromSnapshot(): string
    {
        $raw = $this->snapshot ? @gzinflate((string) base64_decode($this->snapshot)) : false;
        $size = $this->width * $this->height;

        return is_string($raw) && strlen($raw) === $size ? $raw : str_repeat("\0", $size);
    }
}
