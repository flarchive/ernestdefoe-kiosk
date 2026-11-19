<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Group memberships Kiosk added, and nobody else.
 *
 * 🚨 This table is the only thing that lets Kiosk take a group AWAY.
 * `group_user` cannot say who put a member in a group, so without it "remove
 * them when access ends" would also remove the moderator an admin added by
 * hand, or a member who was in the group before they ever bought anything.
 * A row here means "we added them, so we may remove them"; no row means the
 * membership is somebody else's and is never touched.
 */
return Migration::createTable('kiosk_group_grants', function (Blueprint $table) {
    $table->unsignedInteger('user_id');
    $table->unsignedInteger('group_id');
    $table->dateTime('granted_at');

    $table->primary(['user_id', 'group_id']);
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    $table->foreign('group_id')->references('id')->on('groups')->cascadeOnDelete();
});
