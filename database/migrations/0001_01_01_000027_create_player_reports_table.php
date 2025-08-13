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
        Schema::create('player_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reported_user_id')->constrained('users');
            $table->foreignId('reported_by_user_id')->constrained('users');
            $table->foreignId('event_id')->nullable()->constrained()->onDelete('cascade'); // depends on what the user has joined in
            $table->string('reason'); //dropdown in front end
            $table->text('details')->nullable();
            $table->timestamp('reported_at')->useCurrent();
            $table->enum('status', ['open', 'reviewed', 'dismissed'])->default('open');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('player_reports');
    }
}; 