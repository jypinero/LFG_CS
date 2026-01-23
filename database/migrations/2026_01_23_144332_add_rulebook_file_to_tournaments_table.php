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
        Schema::table('tournaments', function (Blueprint $table) {
            if (!Schema::hasColumn('tournaments', 'rulebook_file')) {
                $table->string('rulebook_file')->nullable()->after('rules');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            if (Schema::hasColumn('tournaments', 'rulebook_file')) {
                $table->dropColumn('rulebook_file');
            }
        });
    }
};
