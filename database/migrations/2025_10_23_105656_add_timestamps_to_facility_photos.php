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
        Schema::table('facility_photos', function (Blueprint $table) {
            if (! Schema::hasColumn('facility_photos', 'created_at')) {
                $table->timestamps();
            }
        });
    }

    public function down(): void
    {
        Schema::table('facility_photos', function (Blueprint $table) {
            if (Schema::hasColumn('facility_photos', 'created_at')) {
                $table->dropTimestamps();
            }
        });
    }
};
