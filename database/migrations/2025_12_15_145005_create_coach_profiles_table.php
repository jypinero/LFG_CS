<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCoachProfilesTable extends Migration
{
    public function up()
    {
        Schema::create('coach_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->text('bio')->nullable();
            $table->json('specializations')->nullable();
            $table->decimal('hourly_rate', 8, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->json('availability')->nullable();
            $table->json('location')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->integer('years_experience')->default(0);
            $table->json('certifications')->nullable();
            $table->decimal('rating', 3, 2)->nullable();
            $table->integer('total_reviews')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('coach_profiles');
    }
}