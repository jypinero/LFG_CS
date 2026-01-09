<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'challonge_access_token')) {
                $table->text('challonge_access_token')->nullable()->change();
            }
            if (Schema::hasColumn('users', 'challonge_refresh_token')) {
                $table->text('challonge_refresh_token')->nullable()->change();
            }
            // optional: store challonge_uid as string larger than default
            if (Schema::hasColumn('users', 'challonge_uid')) {
                $table->string('challonge_uid', 191)->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'challonge_access_token')) {
                $table->string('challonge_access_token', 255)->nullable()->change();
            }
            if (Schema::hasColumn('users', 'challonge_refresh_token')) {
                $table->string('challonge_refresh_token', 255)->nullable()->change();
            }
            if (Schema::hasColumn('users', 'challonge_uid')) {
                $table->string('challonge_uid', 191)->nullable()->change();
            }
        });
    }
};