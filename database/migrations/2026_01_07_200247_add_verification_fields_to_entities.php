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
        
        // Venues - Add verified_by and AI verification flag if missing
        Schema::table('venues', function (Blueprint $table) {
            if (!Schema::hasColumn('venues', 'verified_by')) {
                $table->foreignId('verified_by')->nullable()->after('verified_at')->constrained('users')->onDelete('set null');
            }
            if (!Schema::hasColumn('venues', 'verified_by_ai')) {
                $table->boolean('verified_by_ai')->default(false)->after('verified_at');
            }
        });
        
        // Teams - Add AI verification flag (already has certification fields)
        Schema::table('teams', function (Blueprint $table) {
            if (!Schema::hasColumn('teams', 'verified_by_ai')) {
                $table->boolean('verified_by_ai')->default(false)->after('certification_verified_at');
            }
        });
        
        // Coach Profiles - Add verification fields (already has is_verified)
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Users
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['verified_by']);
            $table->dropColumn([
                'is_pro_athlete',
                'verified_at',
                'verified_by',
                'verification_notes',
                'verified_by_ai'
            ]);
        });
        
        // Venues
        Schema::table('venues', function (Blueprint $table) {
            $table->dropColumn('verified_by_ai');
        });
        
        // Teams
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('verified_by_ai');
        });
        
        // Coach Profiles
        Schema::table('coach_profiles', function (Blueprint $table) {
            $table->dropForeign(['verified_by']);
            $table->dropColumn([
                'verified_at',
                'verified_by',
                'verification_notes',
                'verified_by_ai'
            ]);
        });
    }
};
