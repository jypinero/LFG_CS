<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('event_participants', function (Blueprint $table) {
            $table->unsignedBigInteger('tournament_id')->nullable()->after('event_id')->index();
            $table->foreign('tournament_id')->references('id')->on('tournaments')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::table('event_participants', function (Blueprint $table) {
            $table->dropForeign(['tournament_id']);
            $table->dropColumn('tournament_id');
        });
    }
};
