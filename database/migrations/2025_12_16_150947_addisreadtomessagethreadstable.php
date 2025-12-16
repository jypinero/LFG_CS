<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddIsReadToMessageThreadsTable extends Migration
{
    public function up()
    {
        Schema::table('message_threads', function (Blueprint $table) {
            $table->boolean('is_read')->default(false);
        });
    }

    public function down()
    {
        Schema::table('message_threads', function (Blueprint $table) {
            $table->dropColumn('is_read');
        });
    }
}