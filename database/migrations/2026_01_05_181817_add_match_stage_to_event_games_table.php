<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMatchStageToEventGamesTable extends Migration
{
    public function up()
    {
        Schema::table('event_games', function (Blueprint $table) {
            $table->string('match_stage')->default('winners')->after('round_number')
                  ->comment('Bracket stage: winners, losers, grand_final');
        });
    }

    public function down()
    {
        Schema::table('event_games', function (Blueprint $table) {
            $table->dropColumn('match_stage');
        });
    }
}
