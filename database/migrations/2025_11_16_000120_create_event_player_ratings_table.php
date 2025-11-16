<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		Schema::create('event_player_ratings', function (Blueprint $table) {
			$table->id();
			$table->unsignedBigInteger('event_id');
			$table->unsignedBigInteger('rater_id');
			$table->unsignedBigInteger('ratee_id');
			$table->unsignedTinyInteger('stars'); // 1-5
			$table->text('comment')->nullable();
			$table->timestamps();

			$table->unique(['event_id', 'rater_id', 'ratee_id']);
			$table->index(['event_id', 'ratee_id']);
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('event_player_ratings');
	}
};


