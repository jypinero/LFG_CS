<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
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
    }
};



