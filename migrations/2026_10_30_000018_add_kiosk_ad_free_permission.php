<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

// Staff never see ads. Admins do, so they can check what members see.
return Migration::addPermissions([
    'kiosk.adFree' => Group::MODERATOR_ID,
]);
