<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * A season is one canvas, from its start until a moderator ends it. Size and
 * palette are fixed for the season's life (a tile stores a palette index).
 *
 * The picture is `snapshot` (raw deflate, base64, one byte per tile) as of
 * placement `snapshot_upto`; placements after that are the tail, applied on
 * read and folded in now and then under the season's row lock. `epoch` goes
 * up when a rollback rewrites history, so open canvases know to reload
 * instead of applying a diff on top of the wrong picture.
 */
return Migration::createTable('mural_seasons', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('number')->unique();
    $table->string('title', 100)->nullable();
    $table->unsignedSmallInteger('width');
    $table->unsignedSmallInteger('height');
    $table->text('palette');
    $table->mediumText('snapshot');
    $table->unsignedBigInteger('snapshot_upto')->default(0);
    $table->unsignedInteger('epoch')->default(0);
    $table->unsignedInteger('placements_count')->default(0);
    $table->unsignedInteger('painters_count')->default(0);
    $table->dateTime('started_at');
    $table->dateTime('ended_at')->nullable()->index();
    $table->string('image_path', 150)->nullable();
    $table->string('timelapse_path', 150)->nullable();
    // Saving the finished picture: null (not asked), building, failed, done. Claimed before work starts.
    $table->string('archive_state', 10)->nullable();
    $table->dateTime('archive_at')->nullable();
    $table->timestamps();
});
