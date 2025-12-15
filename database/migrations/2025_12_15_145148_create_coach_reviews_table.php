<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCoachReviewsTable extends Migration
{
    public function up()
    {
        Schema::create('coach_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('coach_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('training_session_id')->nullable();
            $table->tinyInteger('rating')->unsigned();
            $table->text('review')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamps();

            $table->unique(['coach_id', 'student_id', 'training_session_id']);
            $table->foreign('coach_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('student_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('training_session_id')->references('id')->on('training_sessions')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('coach_reviews');
    }
}