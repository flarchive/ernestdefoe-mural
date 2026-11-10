<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

// Anyone may look, members paint, moderators roll back, ban and end seasons.
return Migration::addPermissions([
    'mural.view' => Group::GUEST_ID,
    'mural.paint' => Group::MEMBER_ID,
    'mural.moderate' => Group::MODERATOR_ID,
]);
