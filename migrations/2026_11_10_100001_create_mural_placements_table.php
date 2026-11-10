<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * The append-only log: every tile ever placed. The timelapse plays it, "who
 * placed this" reads the newest live row for a tile, and a rollback marks
 * rows with its id instead of deleting them, so it can be undone.
 */
return Migration::createTable('mural_placements', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->unsignedInteger('season_id');
    $table->unsignedInteger('user_id')->nullable();
    $table->unsignedSmallInteger('x');
    $table->unsignedSmallInteger('y');
    $table->unsignedTinyInteger('color');
    $table->unsignedInteger('rollback_id')->nullable();
    $table->dateTime('created_at');

    $table->index(['season_id', 'id']);
    $table->index(['season_id', 'y', 'x']);
    $table->index(['season_id', 'created_at']);
    $table->index(['user_id', 'season_id']);
    $table->index('rollback_id');

    $table->foreign('season_id')->references('id')->on('mural_seasons')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
});
