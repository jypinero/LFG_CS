<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPhotoToTournamentsTable extends Migration
{
    public function up()
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->string('photo')->nullable()->after('name');
        });
    }

    public function down()
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn('photo');
        });
    }
}