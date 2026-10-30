<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * One row per thing somebody bought or was given: a one-off, a fixed term or
 * a subscription.
 *
 * 🚨 The name, amount, currency and terms are COPIED in when the purchase is
 * made and never re-read from the product or price. Editing a product must
 * not rewrite what somebody already bought, and a receipt must keep saying
 * what they were actually charged.
 */
return Migration::createTable('kiosk_purchases', function (Blueprint $table) {
    $table->increments('id');
    // The reference sent to the provider and printed on receipts.
    $table->string('number', 24)->unique();
    $table->unsignedInteger('user_id');
    $table->unsignedInteger('product_id')->nullable();
    $table->unsignedInteger('price_id')->nullable();
    $table->string('product_name', 150);
    $table->unsignedInteger('amount_cents');
    $table->char('currency', 3);
    $table->string('kind', 12);
    $table->string('interval', 8)->nullable();
    $table->unsignedSmallInteger('interval_count')->default(1);
    $table->unsignedSmallInteger('term_days')->nullable();
    // paypal, stripe, … or grant for one an admin gave away.
    $table->string('provider', 20);
    $table->string('mode', 8)->nullable();
    // The provider's order or subscription id.
    $table->string('provider_ref', 191)->nullable();
    // pending → active → canceled (still running to period_end) → ended;
    // or pending → expired (checkout abandoned); or refunded.
    $table->string('status', 12);
    $table->dateTime('period_start')->nullable();
    // When access stops; null = forever.
    $table->dateTime('period_end')->nullable();
    $table->boolean('cancel_at_period_end')->default(false);
    $table->string('note', 191)->nullable();
    $table->dateTime('created_at');
    $table->dateTime('updated_at');
    $table->dateTime('paid_at')->nullable();
    $table->dateTime('ended_at')->nullable();
    $table->dateTime('reminded_at')->nullable();

    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    $table->foreign('product_id')->references('id')->on('kiosk_products')->nullOnDelete();
    $table->foreign('price_id')->references('id')->on('kiosk_prices')->nullOnDelete();
    $table->index(['user_id', 'status']);
    $table->index(['provider', 'provider_ref']);
    $table->index(['status', 'period_end']);
    $table->index(['status', 'created_at']);
});
