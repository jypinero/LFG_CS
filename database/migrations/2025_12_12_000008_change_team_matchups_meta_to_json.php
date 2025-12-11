<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ChangeTeamMatchupsMetaToJson extends Migration
{
    public function up()
    {
        // requires doctrine/dbal to run ->change()
        Schema::table('team_matchups', function (Blueprint $table) {
            if (! Schema::hasColumn('team_matchups', 'meta')) {
                $table->json('meta')->nullable();
            } else {
                $table->json('meta')->nullable()->change();
            }
            if (Schema::hasColumn('team_matchups', 'penalties')) {
                $table->json('penalties')->nullable()->change();
            } else {
                $table->json('penalties')->nullable();
            }
        });
    }

    public function down()
    {
        Schema::table('team_matchups', function (Blueprint $table) {
            // best-effort rollback to text; adjust if your previous schema differs
            if (Schema::hasColumn('team_matchups', 'meta')) {
                $table->text('meta')->nullable()->change();
            }
            if (Schema::hasColumn('team_matchups', 'penalties')) {
                $table->text('penalties')->nullable()->change();
            }
        });
    }
}