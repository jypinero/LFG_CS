<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        if (! Schema::hasColumn('events', 'is_rating_notified')) {
            Schema::table('events', function (Blueprint $table) {
                $table->boolean('is_rating_notified')->default(false)->after('approved_at');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('events', 'is_rating_notified')) {
            Schema::table('events', function (Blueprint $table) {
                $table->dropColumn('is_rating_notified');
            });
        }
    }
};
