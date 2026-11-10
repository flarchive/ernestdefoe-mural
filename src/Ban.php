<?php

namespace ErnestDefoe\Mural;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $user_id
 * @property int|null $banned_by
 * @property string|null $reason
 * @property Carbon|null $until
 * @property Carbon $created_at
 * @property User|null $user
 */
class Ban extends AbstractModel
{
    protected $table = 'mural_bans';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $casts = ['user_id' => 'integer', 'banned_by' => 'integer', 'until' => 'datetime', 'created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Bans in force: forever, or until a moment still to come.
     *
     * @return Builder<Ban>
     */
    public static function active(): Builder
    {
        return self::query()->where(fn (Builder $q) => $q->whereNull('until')->orWhere('until', '>', Carbon::now()));
    }
}
