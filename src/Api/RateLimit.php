<?php

namespace ErnestDefoe\Mural\Api;

use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Http\Message\ServerRequestInterface;

/** Things one member (or one guest address) may do so many times a minute. */
class RateLimit
{
    public function __construct(private readonly Cache $cache)
    {
    }

    public function exceeded(ServerRequestInterface $request, User $actor, string $kind, int $perMinute): bool
    {
        $who = $actor->isGuest() ? 'ip'.sha1((string) ($request->getAttribute('ipAddress') ?? '')) : (string) $actor->id;
        $key = 'ernestdefoe-mural.rate.'.$kind.':'.$who.':'.intdiv(time(), 60);
        $this->cache->add($key, 0, 120);

        return (int) $this->cache->increment($key) > $perMinute;
    }
}
