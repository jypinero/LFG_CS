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
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('event_type', 30); 
            $table->string('sport');
            $table->foreignId('venue_id')->constrained();
            $table->unsignedInteger('slots');
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time')->nullable();
            $table->foreignId('created_by')->constrained('users');
            //boolean friendly game -> no need verification
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};