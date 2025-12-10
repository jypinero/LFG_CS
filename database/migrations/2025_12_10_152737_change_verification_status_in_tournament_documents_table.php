<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ChangeVerificationStatusInTournamentDocumentsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // MySQL: convert column to ENUM of allowed values to avoid truncation
        DB::statement("
            ALTER TABLE `tournament_documents`
            MODIFY `verification_status` ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // revert to varchar to be safe
        DB::statement("
            ALTER TABLE `tournament_documents`
            MODIFY `verification_status` VARCHAR(50) NOT NULL DEFAULT 'pending'
        ");
    }
};
