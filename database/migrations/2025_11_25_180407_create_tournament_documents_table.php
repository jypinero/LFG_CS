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
        Schema::create('tournament_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tournament_id')->constrained()->onDelete('cascade');
            $table->foreignId('participant_id')->nullable()->constrained('tournament_participants')->nullOnDelete();

            $table->string('document_type')->nullable();
            $table->string('document_name')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();

            $table->enum('verification_status', ['pending','approved','rejected'])->default('pending');
            $table->text('rejection_reason')->nullable();

            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

            $table->index('tournament_id');
            $table->index('participant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tournament_documents');
    }
};
