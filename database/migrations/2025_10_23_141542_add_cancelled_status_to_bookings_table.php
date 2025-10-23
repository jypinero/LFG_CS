<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add 'cancelled' to the status enum
        DB::statement("ALTER TABLE `bookings` MODIFY `status` ENUM('pending', 'approved', 'denied', 'cancelled') NOT NULL DEFAULT 'pending'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert back to original enum values without 'cancelled'
        DB::statement("ALTER TABLE `bookings` MODIFY `status` ENUM('pending', 'approved', 'denied') NOT NULL DEFAULT 'pending'");
    }
};
