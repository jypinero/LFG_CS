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
        Schema::table('venues', function (Blueprint $table) {
            $table->string('phone_number')->nullable()->after('description');
            $table->string('email')->nullable()->after('phone_number');
            $table->string('facebook_url')->nullable()->after('email');
            $table->string('instagram_url')->nullable()->after('facebook_url');
            $table->string('website')->nullable()->after('instagram_url');
            $table->text('house_rules')->nullable()->after('website');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            $table->dropColumn(['phone_number', 'email', 'facebook_url', 'instagram_url', 'website', 'house_rules']);
        });
    }
};
