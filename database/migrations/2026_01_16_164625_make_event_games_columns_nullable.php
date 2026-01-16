<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class MakeEventGamesColumnsNullable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // requires doctrine/dbal to run column->change()
        if (! Schema::hasTable('event_games')) return;

        Schema::table('event_games', function (Blueprint $table) {
            if (Schema::hasColumn('event_games', 'round_number')) {
                $table->integer('round_number')->nullable()->change();
            }
            if (Schema::hasColumn('event_games', 'match_stage')) {
                $table->string('match_stage')->nullable()->change();
            }
            if (Schema::hasColumn('event_games', 'match_number')) {
                $table->integer('match_number')->nullable()->change();
            }
            if (Schema::hasColumn('event_games', 'team_a_id')) {
                $table->unsignedBigInteger('team_a_id')->nullable()->change();
            }
            if (Schema::hasColumn('event_games', 'team_b_id')) {
                $table->unsignedBigInteger('team_b_id')->nullable()->change();
            }
            if (Schema::hasColumn('event_games', 'score_a')) {
                $table->integer('score_a')->nullable()->change();
            }
            if (Schema::hasColumn('event_games', 'score_b')) {
                $table->integer('score_b')->nullable()->change();
            }
            if (Schema::hasColumn('event_games', 'winner_team_id')) {
                $table->unsignedBigInteger('winner_team_id')->nullable()->change();
            }
            if (Schema::hasColumn('event_games', 'challonge_match_id')) {
                $table->string('challonge_match_id')->nullable()->change();
            }
            if (Schema::hasColumn('event_games', 'challonge_match_url')) {
                $table->text('challonge_match_url')->nullable()->change();
            }
            if (Schema::hasColumn('event_games', 'game_date')) {
                $table->date('game_date')->nullable()->change();
            }
            if (Schema::hasColumn('event_games', 'start_time')) {
                $table->time('start_time')->nullable()->change();
            }
            if (Schema::hasColumn('event_games', 'end_time')) {
                $table->time('end_time')->nullable()->change();
            }
            if (Schema::hasColumn('event_games', 'status')) {
                $table->string('status')->nullable()->change();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('event_games')) return;

        Schema::table('event_games', function (Blueprint $table) {
            if (Schema::hasColumn('event_games', 'round_number')) {
                $table->integer('round_number')->nullable(false)->change();
            }
            if (Schema::hasColumn('event_games', 'match_stage')) {
                $table->string('match_stage')->nullable(false)->change();
            }
            if (Schema::hasColumn('event_games', 'match_number')) {
                $table->integer('match_number')->nullable(false)->change();
            }
            if (Schema::hasColumn('event_games', 'team_a_id')) {
                $table->unsignedBigInteger('team_a_id')->nullable(false)->change();
            }
            if (Schema::hasColumn('event_games', 'team_b_id')) {
                $table->unsignedBigInteger('team_b_id')->nullable(false)->change();
            }
            if (Schema::hasColumn('event_games', 'score_a')) {
                $table->integer('score_a')->nullable(false)->change();
            }
            if (Schema::hasColumn('event_games', 'score_b')) {
                $table->integer('score_b')->nullable(false)->change();
            }
            if (Schema::hasColumn('event_games', 'winner_team_id')) {
                $table->unsignedBigInteger('winner_team_id')->nullable(false)->change();
            }
            if (Schema::hasColumn('event_games', 'challonge_match_id')) {
                $table->string('challonge_match_id')->nullable(false)->change();
            }
            if (Schema::hasColumn('event_games', 'challonge_match_url')) {
                $table->text('challonge_match_url')->nullable(false)->change();
            }
            if (Schema::hasColumn('event_games', 'game_date')) {
                $table->date('game_date')->nullable(false)->change();
            }
            if (Schema::hasColumn('event_games', 'start_time')) {
                $table->time('start_time')->nullable(false)->change();
            }
            if (Schema::hasColumn('event_games', 'end_time')) {
                $table->time('end_time')->nullable(false)->change();
            }
            if (Schema::hasColumn('event_games', 'status')) {
                $table->string('status')->nullable(false)->change();
            }
        });
    }
};
