<?php

namespace ErnestDefoe\Mural;

use Flarum\Http\SlugManager;
use Flarum\User\User;

/** Members and seasons as the browser draws them. */
class Presenter
{
    public function __construct(private readonly SlugManager $slugs, private readonly Archive $archive)
    {
    }

    /** @return array<string, mixed>|null */
    public function user(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'username' => $user->username,
            'displayName' => $user->display_name,
            'slug' => $this->slugs->forResource(User::class)->toSlug($user),
            'avatarUrl' => $user->avatar_url,
        ];
    }

    /** @return array<string, mixed> */
    public function season(Season $season): array
    {
        return [
            'id' => $season->id,
            'number' => $season->number,
            'title' => $season->title,
            'width' => $season->width,
            'height' => $season->height,
            'palette' => $season->colours(),
            'tiles' => $season->placements_count,
            'painters' => $season->painters_count,
            'startedAt' => $season->started_at->toIso8601String(),
            'endedAt' => $season->ended_at?->toIso8601String(),
            'imageUrl' => $this->archive->url($season->image_path),
            'timelapseUrl' => $this->archive->url($season->timelapse_path),
        ];
    }
}
