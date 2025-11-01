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
        Schema::table('facilities', function (Blueprint $table) {
            // Change type from enum to string
            $table->string('type')->change();
            
            // Add new columns
            $table->string('name')->nullable()->after('venue_id');
            $table->integer('capacity')->nullable()->after('name');
            $table->boolean('covered')->default(false)->after('capacity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            $table->dropColumn(['name', 'capacity', 'covered']);
            // Note: We cannot easily restore the enum in down migration
            // Manual intervention would be needed if you want to restore the enum
        });
    }
};
