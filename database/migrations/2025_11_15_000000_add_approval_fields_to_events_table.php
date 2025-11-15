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
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('is_approved')
                ->default(false)
                ->after('cancelled_at');
            $table->timestamp('approved_at')
                ->nullable()
                ->after('is_approved');
        });

        // Mark existing events as approved to keep legacy data visible.
        DB::table('events')->update([
            'is_approved' => true,
            'approved_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['approved_at', 'is_approved']);
        });
    }
};

