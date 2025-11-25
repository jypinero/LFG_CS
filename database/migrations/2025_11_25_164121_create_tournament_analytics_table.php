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
        Schema::create('tournament_analytics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->onDelete('cascade');
            $table->integer('total_participants')->default(0);
            $table->integer('total_teams')->default(0);
            $table->integer('total_games')->default(0);
            $table->integer('completed_games')->default(0);
            $table->integer('no_shows')->default(0);
            $table->decimal('average_rating', 3, 2)->nullable();
            $table->integer('total_ratings')->default(0);
            $table->timestamps();
            
            $table->unique('tournament_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_analytics');
    }
};
