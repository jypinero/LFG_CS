<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEventGamesTable extends Migration
{
    public function up()
    {
        Schema::create('event_games', function (Blueprint $table) {
            $table->id();

            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();

            $table->integer('round_number')->unsigned();
            $table->integer('match_number')->unsigned();

            $table->foreignId('team_a_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('team_b_id')->nullable()->constrained('teams')->nullOnDelete();

            $table->integer('score_a')->nullable();
            $table->integer('score_b')->nullable();

            $table->foreignId('winner_team_id')->nullable()->constrained('teams')->nullOnDelete();

            $table->date('game_date')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();

            $table->enum('status', ['scheduled', 'ongoing', 'completed'])->default('scheduled');

            $table->timestamps();

            $table->unique(['event_id', 'round_number', 'match_number'], 'event_round_match_unique');
            $table->index(['event_id', 'game_date']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('event_games');
    }
}