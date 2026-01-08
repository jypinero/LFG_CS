<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Users - Add pro athlete verification fields
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (!Schema::hasColumn('users', 'is_pro_athlete')) {
                    $table->boolean('is_pro_athlete')->default(false)->after('role_id');
                }
                if (!Schema::hasColumn('users', 'verified_at')) {
                    $table->timestamp('verified_at')->nullable()->after('is_pro_athlete');
                }
                if (!Schema::hasColumn('users', 'verified_by')) {
                    $table->foreignId('verified_by')->nullable()->after('verified_at')->constrained('users')->onDelete('set null');
                }
                if (!Schema::hasColumn('users', 'verification_notes')) {
                    $table->text('verification_notes')->nullable()->after('verified_by');
                }
                if (!Schema::hasColumn('users', 'verified_by_ai')) {
                    $table->boolean('verified_by_ai')->default(false)->after('verification_notes');
                }
            });
        }
        
        // Venues - Add verified_by and AI verification flag if missing
        if (Schema::hasTable('venues')) {
            Schema::table('venues', function (Blueprint $table) {
                if (!Schema::hasColumn('venues', 'verified_by')) {
                    $table->foreignId('verified_by')->nullable()->after('verified_at')->constrained('users')->onDelete('set null');
                }
                if (!Schema::hasColumn('venues', 'verified_by_ai')) {
                    $table->boolean('verified_by_ai')->default(false)->after('verified_at');
                }
            });
        }
        
        // Teams - Add AI verification flag (already has certification fields)
        if (Schema::hasTable('teams')) {
            Schema::table('teams', function (Blueprint $table) {
                if (!Schema::hasColumn('teams', 'verified_by_ai')) {
                    // Find the right position - after certification_verified_at if it exists, otherwise at end
                    if (Schema::hasColumn('teams', 'certification_verified_at')) {
                        $table->boolean('verified_by_ai')->default(false)->after('certification_verified_at');
                    } else {
                        $table->boolean('verified_by_ai')->default(false);
                    }
                }
            });
        }
        
        // Coach Profiles - Add verification fields (already has is_verified)
        if (Schema::hasTable('coach_profiles')) {
            Schema::table('coach_profiles', function (Blueprint $table) {
                if (!Schema::hasColumn('coach_profiles', 'verified_at')) {
                    $table->timestamp('verified_at')->nullable()->after('is_verified');
                }
                if (!Schema::hasColumn('coach_profiles', 'verified_by')) {
                    $table->foreignId('verified_by')->nullable()->after('verified_at')->constrained('users')->onDelete('set null');
                }
                if (!Schema::hasColumn('coach_profiles', 'verification_notes')) {
                    $table->text('verification_notes')->nullable()->after('verified_by');
                }
                if (!Schema::hasColumn('coach_profiles', 'verified_by_ai')) {
                    $table->boolean('verified_by_ai')->default(false)->after('verification_notes');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Users
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (Schema::hasColumn('users', 'verified_by')) {
                    $table->dropForeign(['verified_by']);
                }
                $columns = ['is_pro_athlete', 'verified_at', 'verification_notes', 'verified_by_ai'];
                if (Schema::hasColumn('users', 'verified_by')) {
                    $columns[] = 'verified_by';
                }
                $table->dropColumn($columns);
            });
        }
        
        // Venues
        if (Schema::hasTable('venues')) {
            Schema::table('venues', function (Blueprint $table) {
                if (Schema::hasColumn('venues', 'verified_by_ai')) {
                    $table->dropColumn('verified_by_ai');
                }
                if (Schema::hasColumn('venues', 'verified_by')) {
                    $table->dropForeign(['verified_by']);
                    $table->dropColumn('verified_by');
                }
            });
        }
        
        // Teams
        if (Schema::hasTable('teams')) {
            Schema::table('teams', function (Blueprint $table) {
                if (Schema::hasColumn('teams', 'verified_by_ai')) {
                    $table->dropColumn('verified_by_ai');
                }
            });
        }
        
        // Coach Profiles
        if (Schema::hasTable('coach_profiles')) {
            Schema::table('coach_profiles', function (Blueprint $table) {
                if (Schema::hasColumn('coach_profiles', 'verified_by')) {
                    $table->dropForeign(['verified_by']);
                }
                $columns = ['verified_at', 'verification_notes', 'verified_by_ai'];
                if (Schema::hasColumn('coach_profiles', 'verified_by')) {
                    $columns[] = 'verified_by';
                }
                $table->dropColumn($columns);
            });
        }
    }
};
