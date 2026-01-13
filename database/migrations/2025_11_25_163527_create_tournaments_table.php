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
        if (!Schema::hasTable('tournaments')) {
            Schema::create('tournaments', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->text('description')->nullable();
                $table->enum('type', ['single_sport', 'multisport'])->default('single_sport');
                $table->foreignId('created_by')->constrained('users'); // Organizer
                $table->date('start_date');
                $table->date('end_date');
                $table->date('registration_deadline')->nullable();
                $table->enum('status', ['draft', 'open_registration', 'registration_closed', 'ongoing', 'completed', 'cancelled'])->default('draft');
                $table->boolean('requires_documents')->default(false);
                $table->json('required_documents')->nullable(); // Array of required doc types
                $table->json('settings')->nullable(); // Tournament-specific settings
                $table->integer('max_teams')->nullable();
                $table->integer('min_teams')->default(2);
                $table->decimal('registration_fee', 10, 2)->nullable();
                $table->text('rules')->nullable();
                $table->text('prizes')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tournaments');
    }
};
