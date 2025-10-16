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
        // change role column to VARCHAR(50) to allow values like 'owner'
        DB::statement("ALTER TABLE `team_members` MODIFY `role` VARCHAR(50) NULL;");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // fallback: shrink back to VARCHAR(20) (adjust to your original type if known)
        DB::statement("ALTER TABLE `team_members` MODIFY `role` VARCHAR(20) NULL;");
    }
};
