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
        Schema::table('event_checkins', function (Blueprint $table) {
            $table->enum('checkin_type', ['qr_self', 'manual_by_organizer', 'code_entry'])->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_checkins', function (Blueprint $table) {
            $table->enum('checkin_type', ['qr_self', 'manual_by_organizer'])->change();
        });
    }
};
