<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ExpandTeamMatchupsStatusAndStage extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('team_matchups', function (Blueprint $table) {
            // enlarge to hold values like "winners","round_robin","pending","forfeited", etc.
            $table->string('match_stage', 50)->nullable()->change();
            $table->string('status', 50)->nullable()->default('pending')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('team_matchups', function (Blueprint $table) {
            // revert to something smaller if desired
            $table->string('match_stage', 20)->nullable()->change();
            $table->string('status', 20)->nullable()->default('pending')->change();
        });
    }
};
