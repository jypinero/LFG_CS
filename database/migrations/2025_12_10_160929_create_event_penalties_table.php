<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEventPenaltiesTable extends Migration
{
    public function up()
    {
        Schema::create('event_penalties', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id')->index();
            $table->unsignedBigInteger('issued_by')->nullable()->index();
            $table->unsignedBigInteger('target_user_id')->nullable()->index();
            $table->unsignedBigInteger('target_team_id')->nullable()->index();
            $table->json('penalty_data')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            $table->foreign('issued_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('event_penalties');
    }
}