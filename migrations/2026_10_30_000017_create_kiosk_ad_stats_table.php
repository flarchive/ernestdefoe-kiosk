<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/** One row per ad per day: how often it was seen and clicked. No IPs, no members. */
return Migration::createTable('kiosk_ad_stats', function (Blueprint $table) {
    $table->unsignedInteger('ad_id');
    $table->date('day');
    $table->unsignedInteger('impressions')->default(0);
    $table->unsignedInteger('clicks')->default(0);

    $table->primary(['ad_id', 'day']);
    $table->foreign('ad_id')->references('id')->on('kiosk_ads')->cascadeOnDelete();
});
