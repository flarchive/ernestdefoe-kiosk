<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * The ways a product can be bought.
 *
 * 🚨 A price somebody has paid is never edited. Changing what it costs makes
 * a new row and archives the old one: the old row is what existing
 * subscribers renew at and what their receipts say, and the provider's plan
 * for it (kiosk_price_refs) cannot change amount anyway.
 */
return Migration::createTable('kiosk_prices', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('product_id');
    $table->unsignedInteger('amount_cents');
    $table->char('currency', 3);
    // one_off = forever, fixed_term = term_days of access, recurring = renews
    // every interval_count intervals until cancelled.
    $table->string('kind', 12);
    $table->string('interval', 8)->nullable();
    $table->unsignedSmallInteger('interval_count')->default(1);
    $table->unsignedSmallInteger('term_days')->nullable();
    $table->unsignedSmallInteger('trial_days')->default(0);
    $table->unsignedInteger('position')->default(0);
    $table->dateTime('created_at');
    $table->dateTime('archived_at')->nullable();

    $table->foreign('product_id')->references('id')->on('kiosk_products')->cascadeOnDelete();
    $table->index(['product_id', 'archived_at']);
});
