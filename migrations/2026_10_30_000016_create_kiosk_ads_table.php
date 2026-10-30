<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * The admin's ads. `kind` image (an uploaded picture and a link) or html
 * (pasted as-is: AdSense and other networks' code). `audience` everyone,
 * guests or members. Dates are optional; a row outside them, or switched
 * off, is never sent to anyone.
 */
return Migration::createTable('kiosk_ads', function (Blueprint $table) {
    $table->increments('id');
    $table->string('name', 150);
    $table->string('location', 30);
    $table->string('kind', 10);
    $table->string('image_path', 191)->nullable();
    $table->unsignedSmallInteger('image_width')->nullable();
    $table->unsignedSmallInteger('image_height')->nullable();
    $table->string('link_url', 500)->nullable();
    $table->string('alt_text', 191)->nullable();
    $table->mediumText('html')->nullable();
    $table->string('audience', 10)->default('everyone');
    $table->boolean('is_active')->default(true);
    $table->dateTime('starts_at')->nullable();
    $table->dateTime('ends_at')->nullable();
    $table->dateTime('created_at');
    $table->dateTime('updated_at');
});
