<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('message_threads')) {
            Schema::table('message_threads', function (Blueprint $table) {
                if (!Schema::hasColumn('message_threads', 'type')) {
                    $table->enum('type', ['one_to_one','game_group','team','venue','coach'])->nullable()->after('title');
                }
                if (!Schema::hasColumn('message_threads', 'game_id')) {
                    $table->unsignedBigInteger('game_id')->nullable()->after('type');
                }
                if (!Schema::hasColumn('message_threads', 'team_id')) {
                    $table->unsignedBigInteger('team_id')->nullable()->after('game_id');
                }
                if (!Schema::hasColumn('message_threads', 'venue_id')) {
                    $table->unsignedBigInteger('venue_id')->nullable()->after('team_id');
                }
                if (!Schema::hasColumn('message_threads', 'is_closed')) {
                    $table->boolean('is_closed')->default(false)->after('venue_id');
                }
                if (!Schema::hasColumn('message_threads', 'closed_at')) {
                    $table->timestamp('closed_at')->nullable()->after('is_closed');
                }
            });
        }

        if (Schema::hasTable('thread_participants')) {
            Schema::table('thread_participants', function (Blueprint $table) {
                if (!Schema::hasColumn('thread_participants', 'archived')) {
                    $table->boolean('archived')->default(false)->after('notifications');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('thread_participants')) {
            Schema::table('thread_participants', function (Blueprint $table) {
                if (Schema::hasColumn('thread_participants', 'archived')) {
                    $table->dropColumn('archived');
                }
            });
        }
        if (Schema::hasTable('message_threads')) {
            Schema::table('message_threads', function (Blueprint $table) {
                foreach (['type','game_id','team_id','venue_id','is_closed','closed_at'] as $col) {
                    if (Schema::hasColumn('message_threads', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};


