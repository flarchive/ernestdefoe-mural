<?php

namespace ErnestDefoe\Mural;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $season_id
 * @property int|null $user_id
 * @property int $x
 * @property int $y
 * @property int $color
 * @property int|null $rollback_id
 * @property Carbon $created_at
 * @property User|null $user
 */
class Placement extends AbstractModel
{
    protected $table = 'mural_placements';

    public $timestamps = false;

    protected $casts = [
        'season_id' => 'integer',
        'user_id' => 'integer',
        'x' => 'integer',
        'y' => 'integer',
        'color' => 'integer',
        'rollback_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Rows still on the canvas (not rolled back), in one season.
     *
     * @return Builder<Placement>
     */
    public static function live(int $seasonId): Builder
    {
        return self::query()->where('mural_placements.season_id', $seasonId)->whereNull('mural_placements.rollback_id');
    }
}
