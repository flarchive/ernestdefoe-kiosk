<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * What buying a product opens: `type` is an unlock type's key ("group",
 * "tag", or one another extension registered) and `target_id` the id of the
 * group, tag, album or course in that extension's own table.
 */
return Migration::createTable('kiosk_product_unlocks', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('product_id');
    $table->string('type', 40);
    $table->unsignedInteger('target_id');

    $table->foreign('product_id')->references('id')->on('kiosk_products')->cascadeOnDelete();
    $table->unique(['product_id', 'type', 'target_id']);
    // "Which products unlock this album?" — every Buy button asks it.
    $table->index(['type', 'target_id']);
});
