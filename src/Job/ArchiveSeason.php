<?php

namespace ErnestDefoe\Mural\Job;

use ErnestDefoe\Mural\Archive;
use Flarum\Queue\AbstractJob;

/** Writes a finished season's picture and timelapse (Archive::build), off the request that ended it. */
class ArchiveSeason extends AbstractJob
{
    public function __construct(public int $seasonId)
    {
    }

    public function handle(Archive $archive): void
    {
        $archive->build($this->seasonId);
    }
}
