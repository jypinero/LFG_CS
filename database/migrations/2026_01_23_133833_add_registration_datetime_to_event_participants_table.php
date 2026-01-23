<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('event_participants', function (Blueprint $table) {
            if (!Schema::hasColumn('event_participants', 'registration_datetime')) {
                $table->dateTime('registration_datetime')->nullable()->after('status');
                $table->index('registration_datetime');
            }
        });

        // Backfill existing records: set registration_datetime = created_at
        DB::table('event_participants')
            ->whereNull('registration_datetime')
            ->update(['registration_datetime' => DB::raw('created_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_participants', function (Blueprint $table) {
            if (Schema::hasColumn('event_participants', 'registration_datetime')) {
                $table->dropIndex(['registration_datetime']);
                $table->dropColumn('registration_datetime');
            }
        });
    }
};
