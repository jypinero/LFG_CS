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
        Schema::table('user_otps', function (Blueprint $table) {
            $table->string('type', 20)->default('login')->after('user_id');
        });

        // Update existing records to have 'login' type
        DB::table('user_otps')->whereNull('type')->update(['type' => 'login']);

        // Add index for efficient queries
        Schema::table('user_otps', function (Blueprint $table) {
            $table->index(['user_id', 'type', 'consumed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_otps', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'type', 'consumed_at']);
            $table->dropColumn('type');
        });
    }
};
