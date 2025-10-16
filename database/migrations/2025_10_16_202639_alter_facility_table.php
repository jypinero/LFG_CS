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
        // Change 'type' column from ENUM to VARCHAR(100)
        DB::statement("ALTER TABLE `facilities` MODIFY `type` VARCHAR(255) NOT NULL;");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Optionally revert back to ENUM (adjust values if needed)
        DB::statement("ALTER TABLE `facilities` MODIFY `type` ENUM(
            'stadium', 'arena', 'sport complex', 'gymnasium', 'soccer dom', 'swimming pool',
            'tennis court', 'track and field complex', 'basketball court', 'volleyball court',
            'multipurpose hall', 'fitness center', 'recreational center', 'golf course',
            'archery range', 'shooting range'
        ) NOT NULL;");
    }
};