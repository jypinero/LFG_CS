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
        if (! Schema::hasTable('events') || ! Schema::hasTable('tournaments')) {
            return;
        }

        // Nullify orphaned tournament_ids
        DB::statement("
            UPDATE events e
            LEFT JOIN tournaments t ON e.tournament_id = t.id
            SET e.tournament_id = NULL
            WHERE e.tournament_id IS NOT NULL AND t.id IS NULL
        ");

        // Make tournament_id nullable so FK can be added safely
        Schema::table('events', function (Blueprint $table) {
            $table->unsignedBigInteger('tournament_id')->nullable()->change();
        });

        // Add FK if not exists
        $exists = collect(DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'events'
              AND COLUMN_NAME = 'tournament_id'
              AND REFERENCED_TABLE_NAME = 'tournaments'
        "))->isNotEmpty();

        if (! $exists) {
            Schema::table('events', function (Blueprint $table) {
                $table->foreign('tournament_id')->references('id')->on('tournaments')->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('events')) {
            return;
        }

        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign(['tournament_id']);
            // optional: make not nullable again if desired
            // $table->unsignedBigInteger('tournament_id')->nullable(false)->change();
        });
    }
};
