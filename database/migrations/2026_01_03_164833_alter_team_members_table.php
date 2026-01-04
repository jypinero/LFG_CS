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
        if (! Schema::hasTable('team_members')) {
            return;
        }

        // Add removed_at if missing
        if (! Schema::hasColumn('team_members', 'removed_at')) {
            Schema::table('team_members', function (Blueprint $table) {
                $table->timestamp('removed_at')->nullable()->after('roster_status');
            });
        }

        // Extend roster_status enum to include 'left' and 'removed'
        if (Schema::hasColumn('team_members', 'roster_status')) {
            try {
                // MySQL: modify enum in place
                DB::statement("ALTER TABLE `team_members` MODIFY `roster_status` ENUM('active','inactive','injured','suspended','left','removed') NULL");
            } catch (\Throwable $e) {
                // Fallback: add a temporary column, copy values, drop/rename (safer approach requires doctrine/dbal)
                // If DBAL isn't installed or DB doesn't support MODIFY, do nothing here and install doctrine/dbal then run a manual migration.
            }
        } else {
            // If roster_status missing, create it with desired values
            Schema::table('team_members', function (Blueprint $table) {
                $table->enum('roster_status', ['active','inactive','injured','suspended','left','removed'])->nullable()->after('position');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('team_members')) {
            return;
        }

        // Remove removed_at if present
        if (Schema::hasColumn('team_members', 'removed_at')) {
            Schema::table('team_members', function (Blueprint $table) {
                $table->dropColumn('removed_at');
            });
        }

        // Revert roster_status to original set (remove 'left' and 'removed')
        if (Schema::hasColumn('team_members', 'roster_status')) {
            try {
                DB::statement("ALTER TABLE `team_members` MODIFY `roster_status` ENUM('active','inactive','injured','suspended') NULL");
            } catch (\Throwable $e) {
                // ignore if cannot modify; manual intervention may be required
            }
        }
    }
};
