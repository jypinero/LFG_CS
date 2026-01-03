<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCancelledByToBookingsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'cancelled_by')) {
                $table->unsignedBigInteger('cancelled_by')->nullable()->after('status');
            }
            // optional FK (comment/uncomment if users table exists and you want FK)
            // $table->foreign('cancelled_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // if using foreign keys, drop them first
            // $table->dropForeign(['cancelled_by']);
            if (Schema::hasColumn('bookings', 'cancelled_by')) {
                $table->dropColumn('cancelled_by');
            }
        });
    }
}
