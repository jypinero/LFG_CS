<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddApprovedByAndCancelledByToEventsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (! Schema::hasColumn('events', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('is_approved');
            }
            if (! Schema::hasColumn('events', 'cancelled_by')) {
                $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancelled_at');
            }
            // optional FK (comment/uncomment if users table exists and you want FK)
            // $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
            // $table->foreign('cancelled_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // if using foreign keys, drop them first
            // $table->dropForeign(['approved_by']);
            // $table->dropForeign(['cancelled_by']);
            if (Schema::hasColumn('events', 'approved_by')) {
                $table->dropColumn('approved_by');
            }
            if (Schema::hasColumn('events', 'cancelled_by')) {
                $table->dropColumn('cancelled_by');
            }
        });
    }
}
