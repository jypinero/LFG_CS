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
        Schema::table('bookings', function (Blueprint $table) {
            // Make event_id nullable to support training sessions (which don't have events)
            $table->unsignedBigInteger('event_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Note: This might fail if there are null values
            // You may need to clean up null event_ids before rolling back
            $table->unsignedBigInteger('event_id')->nullable(false)->change();
        });
    }
};
