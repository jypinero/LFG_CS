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
        // Create fresh table if missing (safe path)
        if (! Schema::hasTable('support_tickets')) {
            Schema::create('support_tickets', function (Blueprint $table) {
                $table->id();
                $table->string('subject');
                $table->string('email');
                $table->longText('message');
                $table->string('file_path')->nullable();
                $table->enum('status', ['pending', 'resolved', 'closed'])->default('pending');
                $table->timestamps();

                $table->index('email');
                $table->index('status');
                $table->index('created_at');
            });
            return;
        }

        // 1) Ensure 'email' column exists
        if (! Schema::hasColumn('support_tickets', 'email')) {
            Schema::table('support_tickets', function (Blueprint $table) {
                $table->string('email')->nullable()->after('subject');
            });
        }

        // 2) If submitted_by exists, copy user's email -> support_tickets.email
        if (Schema::hasColumn('support_tickets', 'submitted_by')) {
            try {
                // MySQL UPDATE JOIN
                DB::statement("
                    UPDATE `support_tickets` st
                    JOIN `users` u ON u.id = st.submitted_by
                    SET st.email = u.email
                    WHERE st.email IS NULL OR st.email = ''
                ");
            } catch (\Throwable $e) {
                // ignore if DB driver doesn't support this statement
            }
        }

        // 3) Rename description -> message if needed
        if (Schema::hasColumn('support_tickets', 'description') && ! Schema::hasColumn('support_tickets', 'message')) {
            try {
                DB::statement("ALTER TABLE `support_tickets` CHANGE `description` `message` LONGTEXT NULL");
            } catch (\Throwable $e) {
                // fallback: create message and copy
                Schema::table('support_tickets', function (Blueprint $table) {
                    $table->longText('message')->nullable()->after('email');
                });
                DB::statement("UPDATE `support_tickets` SET `message` = `description`");
            }
        } elseif (! Schema::hasColumn('support_tickets', 'message')) {
            Schema::table('support_tickets', function (Blueprint $table) {
                $table->longText('message')->nullable()->after('email');
            });
        }

        // 4) Add file_path if missing
        if (! Schema::hasColumn('support_tickets', 'file_path')) {
            Schema::table('support_tickets', function (Blueprint $table) {
                $table->string('file_path')->nullable()->after('message');
            });
        }

        // 5) Normalize status values then alter enum
        if (Schema::hasColumn('support_tickets', 'status')) {
            // Map old 'open' -> 'pending'
            try {
                DB::statement("UPDATE `support_tickets` SET `status` = 'pending' WHERE `status` = 'open'");
            } catch (\Throwable $e) { /* ignore */ }

            // Alter column to desired enum (MySQL)
            try {
                DB::statement("ALTER TABLE `support_tickets` MODIFY `status` ENUM('pending','resolved','closed') NOT NULL DEFAULT 'pending'");
            } catch (\Throwable $e) {
                // if not supported, leave as-is
            }
        } else {
            Schema::table('support_tickets', function (Blueprint $table) {
                $table->enum('status', ['pending','resolved','closed'])->default('pending')->after('file_path');
            });
        }

        // 6) Ensure timestamps exist
        if (! Schema::hasColumn('support_tickets', 'created_at')) {
            Schema::table('support_tickets', function (Blueprint $table) {
                $table->timestamps();
            });
        }

        // 7) Drop legacy foreign keys & columns (submitted_by, assigned_to) safely
        if (Schema::hasColumn('support_tickets', 'submitted_by')) {
            try {
                Schema::table('support_tickets', function (Blueprint $table) {
                    $table->dropForeign(['submitted_by']);
                });
            } catch (\Throwable $e) {
                try {
                    DB::statement("ALTER TABLE `support_tickets` DROP FOREIGN KEY `support_tickets_submitted_by_foreign`");
                } catch (\Throwable $e) { /* ignore */ }
            }
            Schema::table('support_tickets', function (Blueprint $table) {
                if (Schema::hasColumn('support_tickets', 'submitted_by')) {
                    $table->dropColumn('submitted_by');
                }
            });
        }

        if (Schema::hasColumn('support_tickets', 'assigned_to')) {
            try {
                Schema::table('support_tickets', function (Blueprint $table) {
                    $table->dropForeign(['assigned_to']);
                });
            } catch (\Throwable $e) {
                try {
                    DB::statement("ALTER TABLE `support_tickets` DROP FOREIGN KEY `support_tickets_assigned_to_foreign`");
                } catch (\Throwable $e) { /* ignore */ }
            }
            Schema::table('support_tickets', function (Blueprint $table) {
                if (Schema::hasColumn('support_tickets', 'assigned_to')) {
                    $table->dropColumn('assigned_to');
                }
            });
        }

        // 8) Create indexes (best-effort)
        try { DB::statement("CREATE INDEX idx_support_tickets_email ON `support_tickets` (`email`)"); } catch (\Throwable $e) {}
        try { DB::statement("CREATE INDEX idx_support_tickets_status ON `support_tickets` (`status`)"); } catch (\Throwable $e) {}
        try { DB::statement("CREATE INDEX idx_support_tickets_created_at ON `support_tickets` (`created_at`)"); } catch (\Throwable $e) {}
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // conservative revert: do not recreate FKs. Remove added columns if present.

        if (Schema::hasColumn('support_tickets', 'file_path')) {
            Schema::table('support_tickets', function (Blueprint $table) {
                $table->dropColumn('file_path');
            });
        }

        // Attempt to revert enum to VARCHAR to be safe
        if (Schema::hasColumn('support_tickets', 'status')) {
            try {
                DB::statement("ALTER TABLE `support_tickets` MODIFY `status` VARCHAR(50) NOT NULL DEFAULT 'pending'");
            } catch (\Throwable $e) { /* ignore */ }
        }

        // Drop indexes added by this migration
        try { DB::statement("DROP INDEX idx_support_tickets_email ON `support_tickets`"); } catch (\Throwable $e) {}
        try { DB::statement("DROP INDEX idx_support_tickets_status ON `support_tickets`"); } catch (\Throwable $e) {}
        try { DB::statement("DROP INDEX idx_support_tickets_created_at ON `support_tickets`"); } catch (\Throwable $e) {}
    }
};
