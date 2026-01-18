<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_games', function (Blueprint $table) {
            if (!Schema::hasColumn('event_games', 'bracket_data')) {
                $table->json('bracket_data')->nullable()->after('status');
            }
            if (!Schema::hasColumn('event_games', 'winner_name')) {
                $table->string('winner_name')->nullable()->after('winner_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('event_games', function (Blueprint $table) {
            if (Schema::hasColumn('event_games', 'bracket_data')) {
                $table->dropColumn('bracket_data');
            }
            if (Schema::hasColumn('event_games', 'winner_name')) {
                $table->dropColumn('winner_name');
            }
        });
    }
};
