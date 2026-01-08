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
    public function up()
    {
        if (Schema::hasTable('facilities')) {
            Schema::table('facilities', function (Blueprint $table) {
                // Check if foreign key already exists
                $foreignKeys = DB::select("
                    SELECT CONSTRAINT_NAME 
                    FROM information_schema.KEY_COLUMN_USAGE 
                    WHERE TABLE_SCHEMA = DATABASE() 
                    AND TABLE_NAME = 'facilities' 
                    AND COLUMN_NAME = 'venue_id' 
                    AND REFERENCED_TABLE_NAME IS NOT NULL
                ");
                if (empty($foreignKeys)) {
                    $table->foreign('venue_id')->references('id')->on('venues')->onDelete('cascade');
                }
            });
        }

        if (Schema::hasTable('venue_photos')) {
            Schema::table('venue_photos', function (Blueprint $table) {
                $foreignKeys = DB::select("
                    SELECT CONSTRAINT_NAME 
                    FROM information_schema.KEY_COLUMN_USAGE 
                    WHERE TABLE_SCHEMA = DATABASE() 
                    AND TABLE_NAME = 'venue_photos' 
                    AND COLUMN_NAME = 'venue_id' 
                    AND REFERENCED_TABLE_NAME IS NOT NULL
                ");
                if (empty($foreignKeys)) {
                    $table->foreign('venue_id')->references('id')->on('venues')->onDelete('cascade');
                }
            });
        }

        if (Schema::hasTable('facility_photos')) {
            Schema::table('facility_photos', function (Blueprint $table) {
                $foreignKeys = DB::select("
                    SELECT CONSTRAINT_NAME 
                    FROM information_schema.KEY_COLUMN_USAGE 
                    WHERE TABLE_SCHEMA = DATABASE() 
                    AND TABLE_NAME = 'facility_photos' 
                    AND COLUMN_NAME = 'facility_id' 
                    AND REFERENCED_TABLE_NAME IS NOT NULL
                ");
                if (empty($foreignKeys)) {
                    $table->foreign('facility_id')->references('id')->on('facilities')->onDelete('cascade');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
