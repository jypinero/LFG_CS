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
        Schema::create('user_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            // Document type and details
            $table->enum('document_type', [
                'government_id',
                'medical_certificate', 
                'waiver_form',
                'insurance_proof',
                'parental_consent',
                'other'
            ]);
            $table->string('custom_type')->nullable(); // For 'other' - please specify
            $table->string('document_name'); // e.g., "Driver's License", "Medical Clearance"
            $table->text('description')->nullable();
            $table->string('reference_number')->nullable(); // ID number, cert number, etc.
            
            // File information
            $table->string('file_path');
            $table->string('file_type'); // pdf, jpg, png, etc.
            $table->integer('file_size'); // bytes
            
            // Optional validity fields (only if applicable)
            $table->string('issued_by')->nullable(); // For IDs/medical certs
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            
            // Verification workflow
            $table->enum('verification_status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->foreignId('verified_by')->nullable()->constrained('users');
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_notes')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_documents');
    }
};
















