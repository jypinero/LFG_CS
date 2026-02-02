<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Convert document_type from enum to string (VARCHAR) for flexibility
     */
    public function up(): void
    {
        // MySQL specific: Alter enum to string
        // First, change the column type to string
        DB::statement("ALTER TABLE `entity_documents` MODIFY COLUMN `document_type` VARCHAR(255) NOT NULL");
    }

    /**
     * Reverse the migrations.
     * Convert back to enum (restore original enum values)
     */
    public function down(): void
    {
        // Restore enum with original values
        DB::statement("ALTER TABLE `entity_documents` MODIFY COLUMN `document_type` ENUM(
            'government_id',
            'medical_certificate',
            'waiver_form',
            'insurance_proof',
            'parental_consent',
            'business_license',
            'certification',
            'registration',
            'other'
        ) NOT NULL");
    }
};
