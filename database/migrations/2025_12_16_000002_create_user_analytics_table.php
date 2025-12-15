<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserAnalyticsTable extends Migration
{
    public function up()
    {
        Schema::create('user_analytics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->integer('consistency_streak')->default(0);
            $table->decimal('completion_rate_percent', 5, 2)->default(0.00);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('user_analytics');
    }
}