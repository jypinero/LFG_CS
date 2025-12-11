<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class FixTournamentAnnouncementsPriorityColumn extends Migration
{
    public function up()
    {
        Schema::table('tournament_announcements', function (Blueprint $table) {
            $table->string('priority', 20)->change();
        });
    }

    public function down()
    {
        Schema::table('tournament_announcements', function (Blueprint $table) {
            $table->string('priority', 1)->change();
        });
    }
}