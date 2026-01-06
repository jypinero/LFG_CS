<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (!Schema::hasColumn('events', 'champion_team_id')) {
                $table->foreignId('champion_team_id')
                    ->nullable()
                    ->after('game_status')
                    ->constrained('teams')
                    ->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (Schema::hasColumn('events', 'champion_team_id')) {
                $table->dropForeign(['champion_team_id']);
                $table->dropColumn('champion_team_id');
            }
        });
    }
};
