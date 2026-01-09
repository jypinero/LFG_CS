<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('event_games')) {
            Schema::table('event_games', function (Blueprint $table) {
                $table->string('challonge_match_id')->nullable()->after('winner_team_id')->index();
                $table->string('challonge_match_url')->nullable()->after('challonge_match_id');
            });
        }

        if (Schema::hasTable('event_participants')) {
            Schema::table('event_participants', function (Blueprint $table) {
                $table->string('challonge_participant_id')->nullable()->after('id')->index();
            });
        }

        if (Schema::hasTable('teams')) {
            Schema::table('teams', function (Blueprint $table) {
                $table->string('challonge_participant_id')->nullable()->after('id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('event_games')) {
            Schema::table('event_games', function (Blueprint $table) {
                $table->dropColumn(['challonge_match_id', 'challonge_match_url']);
            });
        }

        if (Schema::hasTable('event_participants')) {
            Schema::table('event_participants', function (Blueprint $table) {
                $table->dropColumn('challonge_participant_id');
            });
        }

        if (Schema::hasTable('teams')) {
            Schema::table('teams', function (Blueprint $table) {
                $table->dropColumn('challonge_participant_id');
            });
        }
    }
};