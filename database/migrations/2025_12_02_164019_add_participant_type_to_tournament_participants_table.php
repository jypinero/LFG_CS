<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddParticipantTypeToTournamentParticipantsTable extends Migration
{
    public function up()
    {
        Schema::table('tournament_participants', function (Blueprint $table) {
            $table->enum('participant_type', ['individual', 'team'])->default('individual')->after('team_id');
        });
    }

    public function down()
    {
        Schema::table('tournament_participants', function (Blueprint $table) {
            $table->dropColumn('participant_type');
        });
    }
}