<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('rating_score', 3, 2)->default(0.00)->after('remember_token');
            $table->unsignedInteger('rating_count')->default(0)->after('rating_score');
            $table->unsignedTinyInteger('rating_star')->default(0)->after('rating_count');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['rating_score', 'rating_count', 'rating_star']);
        });
    }
};
