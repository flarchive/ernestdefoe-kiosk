<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * The single answer to "may this person in?": user X holds item Y (an unlock
 * type and a target id) from starts_at until ends_at (null = forever),
 * because of purchase Z. Every gate asks this table and nothing else.
 *
 * `closed_at` is set once access has actually been taken away (the group
 * removed, the enrolment ended), so the sweep does that exactly once.
 */
return Migration::createTable('kiosk_entitlements', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('user_id');
    $table->unsignedInteger('purchase_id');
    $table->string('type', 40);
    $table->unsignedInteger('target_id');
    $table->dateTime('starts_at');
    $table->dateTime('ends_at')->nullable();
    $table->dateTime('closed_at')->nullable();

    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    $table->foreign('purchase_id')->references('id')->on('kiosk_purchases')->cascadeOnDelete();
    $table->unique(['purchase_id', 'type', 'target_id']);
    $table->index(['user_id', 'closed_at']);
    $table->index(['closed_at', 'ends_at']);
    $table->index(['type', 'target_id']);
});
