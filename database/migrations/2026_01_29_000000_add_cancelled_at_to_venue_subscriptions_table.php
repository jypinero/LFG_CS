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
        Schema::table('venue_subscriptions', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('ends_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('venue_subscriptions', function (Blueprint $table) {
            $table->dropColumn('cancelled_at');
        });
    }
};
