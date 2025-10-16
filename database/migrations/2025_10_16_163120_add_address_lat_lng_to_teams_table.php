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
        Schema::table('teams', function (Blueprint $table) {
            if (!Schema::hasColumn('teams', 'address_line')) {
                $table->text('address_line')->nullable()->after('name');
            }
            if (!Schema::hasColumn('teams', 'latitude')) {
                $table->decimal('latitude', 10, 8)->nullable()->after('address_line');
            }
            if (!Schema::hasColumn('teams', 'longitude')) {
                $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            if (Schema::hasColumn('teams', 'longitude')) {
                $table->dropColumn('longitude');
            }
            if (Schema::hasColumn('teams', 'latitude')) {
                $table->dropColumn('latitude');
            }
            if (Schema::hasColumn('teams', 'address_line')) {
                $table->dropColumn('address_line');
            }
        });
    }
};
