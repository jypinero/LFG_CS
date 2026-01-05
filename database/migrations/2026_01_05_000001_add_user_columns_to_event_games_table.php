<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddUserColumnsToEventGamesTable extends Migration
{
    public function up()
    {
        Schema::table('event_games', function (Blueprint $table) {
            $table->foreignId('user_a_id')->nullable()->constrained('users')->nullOnDelete()->after('team_b_id');
            $table->foreignId('user_b_id')->nullable()->constrained('users')->nullOnDelete()->after('user_a_id');
            $table->foreignId('winner_user_id')->nullable()->constrained('users')->nullOnDelete()->after('winner_team_id');
            // optional: index for lookups
            $table->index(['user_a_id', 'user_b_id']);
        });
    }

    public function down()
    {
        Schema::table('event_games', function (Blueprint $table) {
            $table->dropConstrainedForeignId('winner_user_id');
            $table->dropConstrainedForeignId('user_b_id');
            $table->dropConstrainedForeignId('user_a_id');
        });
    }
}