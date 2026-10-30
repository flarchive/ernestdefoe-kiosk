<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Discount codes. `kind` percent (value 1–100) or amount (value in the
 * currency's smallest unit, for prices in that currency). A coupon on a
 * renewing price discounts every renewal: the discounted amount is what the
 * subscription is created at.
 */
return Migration::createTable('kiosk_coupons', function (Blueprint $table) {
    $table->increments('id');
    $table->string('code', 40)->unique();
    $table->string('kind', 10);
    $table->unsignedInteger('value');
    $table->char('currency', 3)->nullable();
    // JSON list of product ids; null = every product.
    $table->text('product_ids')->nullable();
    $table->unsignedInteger('max_uses')->nullable();
    $table->unsignedInteger('used_count')->default(0);
    $table->dateTime('expires_at')->nullable();
    $table->dateTime('created_at');
});
