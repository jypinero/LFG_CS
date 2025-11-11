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
        Schema::table('teams', function (Blueprint $table) {
            $table->string('certification_document')->nullable()->after('certification');
            $table->timestamp('certification_verified_at')->nullable()->after('certification_document');
            $table->foreignId('certification_verified_by')->nullable()->after('certification_verified_at')->constrained('users')->onDelete('set null');
            $table->enum('certification_status', ['pending', 'verified', 'rejected', 'under_review'])->default('pending')->after('certification_verified_by');
            $table->decimal('certification_ai_confidence', 3, 2)->nullable()->after('certification_status');
            $table->text('certification_ai_notes')->nullable()->after('certification_ai_confidence');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropForeign(['certification_verified_by']);
            $table->dropColumn([
                'certification_document',
                'certification_verified_at',
                'certification_verified_by',
                'certification_status',
                'certification_ai_confidence',
                'certification_ai_notes'
            ]);
        });
    }
};
