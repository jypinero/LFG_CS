<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddClientUuidToEventGamesTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('event_games', function (Blueprint $table) {
            if (! Schema::hasColumn('event_games', 'client_uuid')) {
                $table->string('client_uuid')->nullable()->unique()->after('id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_games', function (Blueprint $table) {
            if (Schema::hasColumn('event_games', 'client_uuid')) {
                $table->dropColumn('client_uuid');
            }
        });
    }
};
