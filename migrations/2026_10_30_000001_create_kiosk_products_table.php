<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * What the forum sells. A product is a name and a pitch; what buying it
 * unlocks is in kiosk_product_unlocks and what it costs in kiosk_prices,
 * so one product can unlock several things and be offered at several prices.
 */
return Migration::createTable('kiosk_products', function (Blueprint $table) {
    $table->increments('id');
    $table->string('name', 150);
    $table->string('slug', 170)->unique();
    $table->text('description')->nullable();
    // One benefit per line, shown as a checklist on the product card.
    $table->text('benefits')->nullable();
    $table->string('icon', 80)->nullable();
    $table->string('color', 20)->nullable();
    $table->boolean('is_active')->default(true);
    // Hidden products are left off the store page and sold only through the
    // Buy button on the thing they unlock.
    $table->boolean('is_hidden')->default(false);
    // Groups allowed to buy, as a JSON list of ids; null = any member.
    $table->text('buyer_group_ids')->nullable();
    $table->unsignedInteger('stock')->nullable();
    $table->unsignedInteger('sold_count')->default(0);
    $table->dateTime('available_from')->nullable();
    $table->dateTime('available_until')->nullable();
    $table->unsignedInteger('position')->default(0);
    $table->dateTime('created_at');
    $table->dateTime('updated_at');

    $table->index(['is_active', 'position']);
});
