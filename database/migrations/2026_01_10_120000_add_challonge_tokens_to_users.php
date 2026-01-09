<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('challonge_uid')->nullable()->after('id')->index();
            $table->string('challonge_access_token')->nullable()->after('challonge_uid');
            $table->string('challonge_refresh_token')->nullable()->after('challonge_access_token');
            $table->timestamp('challonge_token_expires_at')->nullable()->after('challonge_refresh_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'challonge_uid',
                'challonge_access_token',
                'challonge_refresh_token',
                'challonge_token_expires_at',
            ]);
        });
    }
};