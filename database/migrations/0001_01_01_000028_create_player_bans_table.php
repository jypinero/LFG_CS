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
        Schema::create('player_bans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('banned_by')->constrained('users');
            $table->foreignId('event_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('reason');
            $table->enum('ban_type', ['suspension', 'permanent']);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->timestamps();
        });
    }
    
    // automated star warnings, <=  3 warning for suspension 
    // < 0, suspension
    // allow support agent to add suspensions/bans

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('player_bans');
    }
}; 