<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlayerRatingsTable extends Migration
{
    public function up()
    {
        Schema::create('player_ratings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('rater_user_id');
            $table->unsignedBigInteger('rated_user_id');
            $table->tinyInteger('rating'); // 1 to 5
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            $table->foreign('rater_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('rated_user_id')->references('id')->on('users')->onDelete('cascade');

            $table->unique(['event_id', 'rater_user_id', 'rated_user_id']); // One rating per pair per event
        });
    }

    public function down()
    {
        Schema::dropIfExists('player_ratings');
    }
};
