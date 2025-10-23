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
        Schema::table('facilities', function (Blueprint $table) {
            $table->foreign('venue_id')->references('id')->on('venues')->onDelete('cascade');
        });

        Schema::table('venue_photos', function (Blueprint $table) {
            $table->foreign('venue_id')->references('id')->on('venues')->onDelete('cascade');
        });

        Schema::table('facility_photos', function (Blueprint $table) {
            $table->foreign('facility_id')->references('id')->on('facilities')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
