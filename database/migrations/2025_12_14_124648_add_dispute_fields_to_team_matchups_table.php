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
        Schema::table('team_matchups', function (Blueprint $table) {
            if (!Schema::hasColumn('team_matchups', 'is_disputed')) {
                $table->boolean('is_disputed')->default(false)->after('status');
            }
            if (!Schema::hasColumn('team_matchups', 'dispute_reason')) {
                $table->text('dispute_reason')->nullable()->after('is_disputed');
            }
            if (!Schema::hasColumn('team_matchups', 'disputed_at')) {
                $table->timestamp('disputed_at')->nullable()->after('dispute_reason');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('team_matchups', function (Blueprint $table) {
            $table->dropColumn(['is_disputed', 'dispute_reason', 'disputed_at']);
        });
    }
};
