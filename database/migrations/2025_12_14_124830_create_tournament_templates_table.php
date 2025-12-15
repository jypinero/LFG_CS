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
        Schema::create('tournament_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', ['single_sport', 'multisport']);
            $table->enum('tournament_type', ['team vs team', 'free for all']);
            $table->json('settings'); // Tournament settings
            $table->json('default_phases')->nullable(); // Default phase structure
            $table->foreignId('created_by')->constrained('users');
            $table->boolean('is_public')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tournament_templates');
    }
};
