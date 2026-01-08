<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('facility_id')->nullable()->after('venue_id');
            $table->unsignedBigInteger('booking_id')->nullable()->after('facility_id');
            
            $table->foreign('facility_id')->references('id')->on('facilities')->onDelete('set null');
            $table->foreign('booking_id')->references('id')->on('bookings')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->dropForeign(['facility_id']);
            $table->dropForeign(['booking_id']);
            $table->dropColumn(['facility_id', 'booking_id']);
        });
    }
};
