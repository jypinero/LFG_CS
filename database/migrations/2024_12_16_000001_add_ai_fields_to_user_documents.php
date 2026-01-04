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
        Schema::table('user_documents', function (Blueprint $table) {
            // AI Processing Status
            $table->boolean('ai_processed')->default(false)->after('verification_notes');
            $table->decimal('ai_confidence_score', 5, 2)->nullable()->after('ai_processed');
            
            // AI Extracted Data (JSON)
            $table->json('ai_extracted_data')->nullable()->after('ai_confidence_score');
            
            // AI Validation Results
            $table->text('ai_validation_notes')->nullable()->after('ai_extracted_data');
            $table->json('ai_flags')->nullable()->after('ai_validation_notes');
            
            // AI Quality Metrics
            $table->decimal('ai_quality_score', 5, 2)->nullable()->after('ai_flags');
            $table->boolean('ai_name_matches')->nullable()->after('ai_quality_score');
            
            // Auto-verification tracking
            $table->boolean('ai_auto_verified')->default(false)->after('ai_name_matches');
            $table->timestamp('ai_processed_at')->nullable()->after('ai_auto_verified');
            
            // OCR extracted text (for debugging)
            $table->text('ai_ocr_text')->nullable()->after('ai_processed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_documents', function (Blueprint $table) {
            $table->dropColumn([
                'ai_processed',
                'ai_confidence_score',
                'ai_extracted_data',
                'ai_validation_notes',
                'ai_flags',
                'ai_quality_score',
                'ai_name_matches',
                'ai_auto_verified',
                'ai_processed_at',
                'ai_ocr_text'
            ]);
        });
    }
};













