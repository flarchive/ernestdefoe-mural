<?php

namespace ErnestDefoe\Mural;

use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\Guest;
use Flarum\User\User;

/**
 * Who may see the mural: anyone who can see the forum AND has mural.view.
 * A forum closed to guests keeps the canvas, painters and stats closed too.
 * Every read endpoint, the page and the forum flag go through here.
 */
final class Access
{
    public static function canView(User $actor): bool
    {
        return $actor->can('viewForum') && $actor->can(Settings::VIEW);
    }

    public static function assertView(User $actor): void
    {
        if (! self::canView($actor)) {
            throw new PermissionDeniedException();
        }
    }

    /** Whether the mural is public: then the picture may be cached by proxies and tiles pushed on the public channel. */
    public static function isPublic(): bool
    {
        static $public = null;

        return $public ??= self::canView(new Guest());
    }
}
