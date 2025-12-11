<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateTeamMatcupsStructure extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Note: requires doctrine/dbal for ->change()
        Schema::table('team_matchups', function (Blueprint $table) {
            // make team ids nullable
            if (Schema::hasColumn('team_matchups', 'team_a_id')) {
                $table->unsignedBigInteger('team_a_id')->nullable()->change();
            }
            if (Schema::hasColumn('team_matchups', 'team_b_id')) {
                $table->unsignedBigInteger('team_b_id')->nullable()->change();
            }
            if (Schema::hasColumn('team_matchups', 'winner_team_id')) {
                $table->unsignedBigInteger('winner_team_id')->nullable()->change();
            }

            // ensure match_stage/status are strings long enough
            if (Schema::hasColumn('team_matchups', 'match_stage')) {
                $table->string('match_stage', 50)->nullable()->change();
            }
            if (Schema::hasColumn('team_matchups', 'status')) {
                $table->string('status', 50)->nullable()->change();
            }

            // meta & penalties to JSON
            if (! Schema::hasColumn('team_matchups', 'meta')) {
                $table->json('meta')->nullable();
            } else {
                $table->json('meta')->nullable()->change();
            }
            if (! Schema::hasColumn('team_matchups', 'penalties')) {
                $table->json('penalties')->nullable();
            } else {
                $table->json('penalties')->nullable()->change();
            }

            // add next_match_id and loser_next_match_id
            if (! Schema::hasColumn('team_matchups', 'next_match_id')) {
                $table->unsignedBigInteger('next_match_id')->nullable()->after('id');
                $table->foreign('next_match_id')->references('id')->on('team_matchups')->onDelete('set null');
            }
            if (! Schema::hasColumn('team_matchups', 'loser_next_match_id')) {
                $table->unsignedBigInteger('loser_next_match_id')->nullable()->after('next_match_id');
                $table->foreign('loser_next_match_id')->references('id')->on('team_matchups')->onDelete('set null');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('team_matchups', function (Blueprint $table) {
            if (Schema::hasColumn('team_matchups', 'next_match_id')) {
                $table->dropForeign(['next_match_id']);
                $table->dropColumn('next_match_id');
            }
            if (Schema::hasColumn('team_matchups', 'loser_next_match_id')) {
                $table->dropForeign(['loser_next_match_id']);
                $table->dropColumn('loser_next_match_id');
            }
            // can't reliably revert json->text without data loss; leaving other columns unchanged
        });
    }
};
