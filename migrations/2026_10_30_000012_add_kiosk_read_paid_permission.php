<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

// Staff read paid discussions without buying them.
return Migration::addPermissions([
    'kiosk.readPaid' => Group::MODERATOR_ID,
]);
