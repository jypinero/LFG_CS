<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class MakeTeamMatchupTeamIdsNullable extends Migration
{
    public function up()
    {
        Schema::table('team_matchups', function (Blueprint $table) {
            $table->unsignedBigInteger('team_a_id')->nullable()->change();
            $table->unsignedBigInteger('team_b_id')->nullable()->change();
            if (Schema::hasColumn('team_matchups','winner_team_id')) {
                $table->unsignedBigInteger('winner_team_id')->nullable()->change();
            }
        });
    }

    public function down()
    {
        Schema::table('team_matchups', function (Blueprint $table) {
            $table->unsignedBigInteger('team_a_id')->nullable(false)->change();
            $table->unsignedBigInteger('team_b_id')->nullable(false)->change();
            if (Schema::hasColumn('team_matchups','winner_team_id')) {
                $table->unsignedBigInteger('winner_team_id')->nullable(false)->change();
            }
        });
    }
}