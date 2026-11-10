<?php

namespace ErnestDefoe\Mural;

final class Settings
{
    public const PREFIX = 'ernestdefoe-mural.';

    public const NAV = self::PREFIX.'nav';
    public const WIDGET = self::PREFIX.'sidebar_widget';
    public const INDEX = self::PREFIX.'allow_indexing';
    public const COOLDOWN = self::PREFIX.'cooldown';
    public const GROUP_COOLDOWNS = self::PREFIX.'group_cooldowns';
    public const WIDTH = self::PREFIX.'width';
    public const HEIGHT = self::PREFIX.'height';
    public const PALETTE = self::PREFIX.'palette';

    public const VIEW = 'mural.view';
    public const PAINT = 'mural.paint';
    public const MODERATE = 'mural.moderate';

    public const DEFAULT_COOLDOWN = 300;
    public const DEFAULT_SIZE = 128;
    public const MIN_SIZE = 16;
    public const MAX_SIZE = 512;
    public const MAX_COOLDOWN = 86400;
}
