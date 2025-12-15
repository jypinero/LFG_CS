<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCoachMatchesTable extends Migration
{
    public function up()
    {
        Schema::create('coach_matches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('coach_id');
            $table->enum('student_action', ['like', 'pass', 'super_like']);
            $table->enum('coach_action', ['like', 'pass', 'super_like'])->nullable();
            $table->enum('match_status', ['pending', 'matched', 'rejected', 'expired'])->default('pending');
            $table->timestamp('matched_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'coach_id']);
            $table->foreign('student_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('coach_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('coach_matches');
    }
}