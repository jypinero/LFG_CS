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
        // Requires doctrine/dbal to be installed: composer require doctrine/dbal
        Schema::table('venue_users', function (Blueprint $table) {
            // Convert enum -> string, set a safe default (change to whatever you prefer)
            $table->string('role')->default('Staff')->change();
        });
    }

    public function down(): void
    {
        Schema::table('venue_users', function (Blueprint $table) {
            // Can't reliably recreate the original enum here — revert to nullable string
            $table->string('role')->nullable()->change();
        });
    }
};
