<?php

namespace ErnestDefoe\Mural\Gdpr;

use ErnestDefoe\Mural\Ban;
use ErnestDefoe\Mural\Placement;
use Flarum\Gdpr\Data\Type;

/**
 * flarum/gdpr: a member's tiles go in their export. Erasing keeps the art
 * (everyone painted around it) but forgets who placed each tile, and drops
 * their cooldown rows and any ban.
 */
class MuralData extends Type
{
    public static function exportDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-mural.lib.gdpr.export');
    }

    public static function anonymizeDescription(): string
    {
        return self::deleteDescription();
    }

    public static function deleteDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-mural.lib.gdpr.delete');
    }

    public function export(): ?array
    {
        $tiles = Placement::query()->where('user_id', $this->user->id)->orderBy('id')
            ->get(['season_id', 'x', 'y', 'color', 'created_at'])->toArray();

        return [['mural/tiles.json' => $this->encodeForExport($tiles)]];
    }

    public function anonymize(): void
    {
        $this->delete();
    }

    public function delete(): void
    {
        Placement::query()->where('user_id', $this->user->id)->update(['user_id' => null]);
        Ban::query()->where('user_id', $this->user->id)->delete();
        Ban::query()->where('banned_by', $this->user->id)->update(['banned_by' => null]);
        resolve('db')->table('mural_painters')->where('user_id', $this->user->id)->delete();
    }
}
