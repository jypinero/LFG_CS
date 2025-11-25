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
        Schema::table('team_matchups', function (Blueprint $table) {
            $table->foreignId('tournament_id')->nullable()->after('event_id')->constrained()->onDelete('cascade');
            $table->integer('round_number')->default(1)->after('match_stage');
            $table->integer('match_number')->nullable()->after('round_number');
            $table->integer('team_a_score')->nullable()->after('scheduled_at');
            $table->integer('team_b_score')->nullable()->after('team_a_score');
            $table->enum('status', ['scheduled', 'in_progress', 'completed', 'forfeited', 'cancelled'])->default('scheduled')->after('winner_team_id');
            $table->timestamp('started_at')->nullable()->after('status');
            $table->timestamp('completed_at')->nullable()->after('started_at');
            $table->text('notes')->nullable()->after('completed_at');
            $table->json('penalties')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('team_matchups', function (Blueprint $table) {
            $table->dropForeignKeyConstraints();
            $table->dropColumn(['tournament_id', 'round_number', 'match_number', 'team_a_score', 'team_b_score', 'status', 'started_at', 'completed_at', 'notes', 'penalties']);
        });
    }
};
