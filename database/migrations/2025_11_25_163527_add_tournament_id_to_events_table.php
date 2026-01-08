<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (!Schema::hasColumn('events', 'tournament_id')) {
                $table->unsignedBigInteger('tournament_id')->nullable()->after('created_by');
            }
            if (!Schema::hasColumn('events', 'game_number')) {
                $table->integer('game_number')->nullable()->after('tournament_id');
            }
            if (!Schema::hasColumn('events', 'game_status')) {
                $table->enum('game_status', ['scheduled', 'in_progress', 'completed', 'cancelled'])->default('scheduled')->after('game_number');
            }
            if (!Schema::hasColumn('events', 'is_tournament_game')) {
                $table->boolean('is_tournament_game')->default(false)->after('game_status');
            }
        });
        
        // Add foreign key constraint only if tournaments table exists
        if (Schema::hasTable('tournaments')) {
            Schema::table('events', function (Blueprint $table) {
                $foreignKeys = DB::select("
                    SELECT CONSTRAINT_NAME 
                    FROM information_schema.KEY_COLUMN_USAGE 
                    WHERE TABLE_SCHEMA = DATABASE() 
                    AND TABLE_NAME = 'events' 
                    AND COLUMN_NAME = 'tournament_id' 
                    AND REFERENCED_TABLE_NAME IS NOT NULL
                ");
                if (empty($foreignKeys)) {
                    $table->foreign('tournament_id')->references('id')->on('tournaments')->onDelete('cascade');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropForeignKeyConstraints();
            $table->dropColumn(['tournament_id', 'game_number', 'game_status', 'is_tournament_game']);
        });
    }
};