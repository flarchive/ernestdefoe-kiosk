<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Every movement of money: the first charge, each renewal, each refund.
 *
 * 🚨 (provider, provider_payment_id) is unique, and that index is what makes
 * a webhook delivered twice record one payment, not two.
 */
return Migration::createTable('kiosk_payments', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('purchase_id');
    $table->string('provider', 20);
    $table->string('provider_payment_id', 191);
    $table->unsignedInteger('amount_cents');
    $table->char('currency', 3);
    // completed, refunded (in full), reversed (chargeback)
    $table->string('status', 12);
    $table->unsignedInteger('refunded_cents')->default(0);
    $table->dateTime('created_at');

    $table->foreign('purchase_id')->references('id')->on('kiosk_purchases')->cascadeOnDelete();
    $table->unique(['provider', 'provider_payment_id']);
    $table->index(['created_at']);
});
