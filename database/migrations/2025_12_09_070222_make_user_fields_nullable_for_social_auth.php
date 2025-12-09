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
        Schema::table('users', function (Blueprint $table) {
            $table->date('birthday')->nullable()->change();
            $table->enum('sex', ['male', 'female', 'other'])->nullable()->change();
            $table->string('contact_number')->nullable()->change();
            $table->string('barangay')->nullable()->change();
            $table->string('city')->nullable()->change();
            $table->string('province')->nullable()->change();
            $table->string('zip_code')->nullable()->change();
            $table->string('password')->nullable()->change(); // Also make password nullable for social auth
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('birthday')->nullable(false)->change();
            $table->enum('sex', ['male', 'female', 'other'])->nullable(false)->change();
            $table->string('contact_number')->nullable(false)->change();
            $table->string('barangay')->nullable(false)->change();
            $table->string('city')->nullable(false)->change();
            $table->string('province')->nullable(false)->change();
            $table->string('zip_code')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });
    }
};
