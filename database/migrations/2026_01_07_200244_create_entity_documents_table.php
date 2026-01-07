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
        Schema::create('entity_documents', function (Blueprint $table) {
            $table->id();
            
            // Polymorphic relationship
            $table->morphs('documentable'); // Creates documentable_type and documentable_id
            
            // Document category for entity-specific filtering
            $table->enum('document_category', [
                'athlete_certification',
                'venue_business',
                'team_registration',
                'coach_license',
                'other'
            ])->default('other');
            
            // Document type and details (reusing user_documents structure)
            $table->enum('document_type', [
                'government_id',
                'medical_certificate', 
                'waiver_form',
                'insurance_proof',
                'parental_consent',
                'business_license',
                'certification',
                'registration',
                'other'
            ]);
            $table->string('custom_type')->nullable(); // For 'other' - please specify
            $table->string('document_name'); // e.g., "Driver's License", "Business Permit"
            $table->text('description')->nullable();
            $table->string('reference_number')->nullable(); // ID number, cert number, etc.
            
            // File information
            $table->string('file_path');
            $table->string('file_type'); // pdf, jpg, png, etc.
            $table->integer('file_size'); // bytes
            
            // Optional validity fields (only if applicable)
            $table->string('issued_by')->nullable(); // For IDs/medical certs/licenses
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            
            // Verification workflow
            $table->enum('verification_status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->foreignId('verified_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_notes')->nullable();
            
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
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes for performance
            $table->index(['documentable_type', 'documentable_id']);
            $table->index('document_category');
            $table->index('verification_status');
            $table->index('ai_processed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entity_documents');
    }
};
