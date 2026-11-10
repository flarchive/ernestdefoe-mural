<?php

namespace ErnestDefoe\Mural;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $season_id
 * @property int|null $actor_id
 * @property string $filters JSON
 * @property int $tiles
 * @property Carbon $created_at
 * @property Carbon|null $undone_at
 * @property User|null $actor
 */
class Rollback extends AbstractModel
{
    protected $table = 'mural_rollbacks';

    public $timestamps = false;

    protected $casts = ['tiles' => 'integer', 'created_at' => 'datetime', 'undone_at' => 'datetime'];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
