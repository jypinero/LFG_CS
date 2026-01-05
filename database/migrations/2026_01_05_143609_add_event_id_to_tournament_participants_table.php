<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddEventIdToTournamentParticipantsTable extends Migration
{
    public function up()
    {
        Schema::table('tournament_participants', function (Blueprint $table) {
            $table->unsignedBigInteger('event_id')->nullable()->after('tournament_id');
            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::table('tournament_participants', function (Blueprint $table) {
            $table->dropForeign(['event_id']);
            $table->dropColumn('event_id');
        });
    }
}
