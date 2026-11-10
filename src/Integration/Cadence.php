<?php

namespace ErnestDefoe\Mural\Integration;

use Flarum\Extension\ExtensionManager;
use Flarum\User\User;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;

/**
 * ernestdefoe/cadence, when it is installed and enabled: a tile on the
 * mural is a day's activity (its own "mural" kind on the activity map) and
 * feeds the member's streak, like a post does. Never costs anyone a tile.
 */
class Cadence
{
    public const KIND = 'mural';

    public function __construct(private readonly Container $container, private readonly ExtensionManager $extensions)
    {
    }

    public function placed(User $user, \DateTimeInterface $at): void
    {
        if (! class_exists(\ErnestDefoe\Cadence\Recorder::class) || ! $this->extensions->isEnabled('ernestdefoe-cadence')) {
            return;
        }

        try {
            $this->container->make(\ErnestDefoe\Cadence\Recorder::class)->record($user->id, $at, self::KIND);
            if (class_exists(\ErnestDefoe\Cadence\Streak\Flames::class)) {
                $this->container->make(\ErnestDefoe\Cadence\Streak\Flames::class)->feed($user->id, true);
            }
        } catch (\Throwable $e) {
            $this->container->make(LoggerInterface::class)->warning('[mural] cadence: '.$e->getMessage());
        }
    }
}
