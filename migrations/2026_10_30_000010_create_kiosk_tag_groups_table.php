<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * The hidden group behind each paid tag.
 *
 * Selling a tag makes it restricted and gives one hidden group its view,
 * start-discussion and reply permissions there. Buyers are then members of
 * that group for permission purposes only (Extend\User::permissionGroups),
 * so Flarum's own tag rules — search, feeds, sitemaps, every extension that
 * respects tag permissions — keep paid discussions from everyone else.
 * The admin sees it as an ordinary group on the Permissions page.
 */
return Migration::createTable('kiosk_tag_groups', function (Blueprint $table) {
    $table->unsignedInteger('tag_id')->primary();
    $table->unsignedInteger('group_id');

    $table->foreign('group_id')->references('id')->on('groups')->cascadeOnDelete();
});
