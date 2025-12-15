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
        Schema::table('tournament_participants', function (Blueprint $table) {
            if (!Schema::hasColumn('tournament_participants', 'withdrawn_at')) {
                $table->timestamp('withdrawn_at')->nullable()->after('approved_at');
            }
        });

        // Update status enum to include 'withdrawn' if needed
        // Note: This may require raw SQL depending on your database
        DB::statement("ALTER TABLE tournament_participants MODIFY COLUMN status ENUM('pending', 'approved', 'rejected', 'banned', 'withdrawn', 'confirmed', 'no_show') DEFAULT 'pending'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tournament_participants', function (Blueprint $table) {
            //
        });
    }
};
