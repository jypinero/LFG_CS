<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (!Schema::hasColumn('events', 'tournament_id')) {
                $table->foreignId('tournament_id')
                    ->nullable()
                    ->after('created_by')
                    ->constrained('tournaments')
                    ->onDelete('cascade');
            }
            if (!Schema::hasColumn('events', 'game_number')) {
                $table->integer('game_number')->nullable()->after('tournament_id');
            }
            if (!Schema::hasColumn('events', 'game_status')) {
                $table->enum('game_status', ['scheduled', 'in_progress', 'completed', 'cancelled'])->default('scheduled')->after('game_number');
            }
            if (!Schema::hasColumn('events', 'is_tournament_game')) {
                $table->boolean('is_tournament_game')->default(false)->after('game_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropForeignKeyConstraints();
            $table->dropColumn(['tournament_id', 'game_number', 'game_status', 'is_tournament_game']);
        });
    }
};