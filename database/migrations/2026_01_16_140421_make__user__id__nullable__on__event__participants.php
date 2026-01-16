<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class MakeUserIdNullableOnEventParticipants extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('event_participants')) {
            return;
        }

        // change() requires doctrine/dbal
        Schema::table('event_participants', function (Blueprint $table) {
            if (Schema::hasColumn('event_participants', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->change();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('event_participants')) {
            return;
        }

        Schema::table('event_participants', function (Blueprint $table) {
            if (Schema::hasColumn('event_participants', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable(false)->change();
            }
        });
    }
};
