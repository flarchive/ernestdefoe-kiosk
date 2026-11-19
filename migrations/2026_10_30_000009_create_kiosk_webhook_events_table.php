<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Every verified webhook delivery. The unique index is the idempotency: a
 * provider retrying an event it already delivered gets "already have it".
 * The admin's webhook log and its Replay button read this table.
 */
return Migration::createTable('kiosk_webhook_events', function (Blueprint $table) {
    $table->increments('id');
    $table->string('provider', 20);
    $table->string('event_id', 191);
    $table->string('type', 100);
    $table->mediumText('payload');
    $table->dateTime('received_at');
    $table->dateTime('processed_at')->nullable();
    $table->string('error', 500)->nullable();

    $table->unique(['provider', 'event_id']);
    $table->index(['received_at']);
});
