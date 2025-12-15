<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTrainingAnalyticsTable extends Migration
{
    public function up()
    {
        Schema::create('training_analytics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->enum('user_type', ['student', 'coach']);
            $table->integer('total_sessions')->default(0);
            $table->integer('completed_sessions')->default(0);
            $table->integer('cancelled_sessions')->default(0);
            $table->decimal('total_hours_trained', 8, 2)->default(0);
            $table->decimal('average_session_duration', 6, 2)->nullable();
            $table->decimal('consistency_score', 5, 2)->nullable();
            $table->decimal('completion_rate', 5, 2)->nullable();
            $table->decimal('average_rating_received', 3, 2)->nullable();
            $table->decimal('average_rating_given', 3, 2)->nullable();
            $table->decimal('total_revenue', 12, 2)->default(0);
            $table->decimal('total_spent', 12, 2)->default(0);
            $table->integer('current_streak_days')->default(0);
            $table->integer('longest_streak_days')->default(0);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'user_type', 'period_start']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('training_analytics');
    }
}