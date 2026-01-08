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
        // Add foreign key constraint if tournaments table exists and constraint doesn't exist
        if (Schema::hasTable('tournaments') && Schema::hasTable('events')) {
            $foreignKeys = DB::select("
                SELECT CONSTRAINT_NAME 
                FROM information_schema.KEY_COLUMN_USAGE 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'events' 
                AND COLUMN_NAME = 'tournament_id' 
                AND REFERENCED_TABLE_NAME = 'tournaments'
            ");
            
            if (empty($foreignKeys)) {
                Schema::table('events', function (Blueprint $table) {
                    $table->foreign('tournament_id')->references('id')->on('tournaments')->onDelete('cascade');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('events')) {
            Schema::table('events', function (Blueprint $table) {
                $table->dropForeign(['tournament_id']);
            });
        }
    }
};
