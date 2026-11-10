<?php

namespace ErnestDefoe\Mural\Job;

use Flarum\Queue\AbstractJob;
use Illuminate\Contracts\Container\Container;

/**
 * Pushes a placed tile (or "reload: history changed") to open canvases
 * through flarum/realtime. A job, so a slow or dead realtime daemon delays
 * nobody's tile. Only position and colour go out; who placed it is asked
 * for separately, with the viewer's own permissions.
 */
class BroadcastPlacement extends AbstractJob
{
    /** @param array<string, mixed> $payload */
    public function __construct(public array $payload)
    {
    }

    public function handle(Container $container): void
    {
        if (! class_exists(\Pusher\Pusher::class) || ! $container->bound(\Pusher\Pusher::class)) {
            return;
        }

        try {
            $container->make(\Pusher\Pusher::class)->trigger('public', 'mural', $this->payload);
        } catch (\Throwable) {
            // Realtime is down: the poll covers it.
        }
    }
}
