<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddUserColumnsToEventGamesTables extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('event_games', function (Blueprint $table) {
            if (! Schema::hasColumn('event_games', 'user_a_id')) {
                $table->foreignId('user_a_id')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('event_games', 'user_b_id')) {
                $table->foreignId('user_b_id')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('event_games', 'winner_user_id')) {
                $table->foreignId('winner_user_id')->nullable()->constrained('users')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_games', function (Blueprint $table) {
            if (Schema::hasColumn('event_games', 'winner_user_id')) $table->dropConstrainedForeignId('winner_user_id');
            if (Schema::hasColumn('event_games', 'user_b_id')) $table->dropConstrainedForeignId('user_b_id');
            if (Schema::hasColumn('event_games', 'user_a_id')) $table->dropConstrainedForeignId('user_a_id');
        });
    }
};
