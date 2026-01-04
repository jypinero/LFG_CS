<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMarketingpostsTable extends Migration
{
    public function up()
    {
        Schema::create('marketingposts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('post_id')->nullable();
            // Use plain unsignedBigInteger columns (no auto-increment)
            $table->unsignedBigInteger('event_id')->nullable();
            $table->unsignedBigInteger('booking_id')->nullable();

            $table->unsignedBigInteger('author_id');
            $table->foreign('author_id')->references('id')->on('users')->onDelete('cascade');

            $table->unsignedBigInteger('venue_id')->nullable();
            $table->foreign('venue_id')->references('id')->on('venues')->onDelete('set null');

            $table->string('image_url')->nullable();
            $table->text('caption')->nullable();
            $table->boolean('create_event')->default(false);
            $table->timestamps();

            $table->index(['author_id','venue_id','event_id','booking_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('marketingposts');
    }
}
