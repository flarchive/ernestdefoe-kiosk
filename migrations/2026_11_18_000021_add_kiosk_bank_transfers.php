<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Bank transfers. An order paid by transfer waits ("awaiting") until the
 * admin or seller sees the money arrive: it keeps when it lapses, and the
 * buyer's proof of payment (a file on a private disk, and a note). Stock is
 * held for it meanwhile in reserved_count, so a slow transfer can't be sold
 * out from under the buyer, and given back when the order is cancelled.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('kiosk_purchases', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable();
            $table->string('proof_path', 100)->nullable();
            $table->string('proof_type', 30)->nullable();
            $table->string('proof_note', 500)->nullable();
            $table->timestamp('proof_at')->nullable();
            $table->boolean('stock_held')->default(false);
        });
        $schema->table('kiosk_products', function (Blueprint $table) {
            $table->unsignedInteger('reserved_count')->default(0);
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('kiosk_products', function (Blueprint $table) {
            $table->dropColumn('reserved_count');
        });
        $schema->table('kiosk_purchases', function (Blueprint $table) {
            $table->dropColumn(['expires_at', 'proof_path', 'proof_type', 'proof_note', 'proof_at', 'stock_held']);
        });
    },
];
