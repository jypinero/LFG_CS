<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class FixEventParticipantsStatusColumn extends Migration
{
    public function up()
    {
        Schema::table('event_participants', function (Blueprint $table) {
            $table->string('status', 50)->change();
        });
    }

    public function down()
    {
        Schema::table('event_participants', function (Blueprint $table) {
            $table->string('status')->change();
        });
    }
}