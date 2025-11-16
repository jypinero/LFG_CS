<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		Schema::create('user_otps', function (Blueprint $table) {
			$table->id();
			$table->unsignedBigInteger('user_id');
			$table->string('code', 6);
			$table->dateTime('expires_at');
			$table->dateTime('consumed_at')->nullable();
			$table->unsignedTinyInteger('attempts')->default(0);
			$table->string('ip', 45)->nullable();
			$table->text('user_agent')->nullable();
			$table->timestamps();

			$table->index(['user_id', 'code']);
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('user_otps');
	}
};


