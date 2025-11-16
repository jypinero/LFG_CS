<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		Schema::create('audit_logs', function (Blueprint $table) {
			$table->id();
			$table->unsignedBigInteger('actor_id')->nullable();
			$table->string('actor_type')->nullable(); // admin|user|system
			$table->string('action');
			$table->string('entity_type')->nullable();
			$table->unsignedBigInteger('entity_id')->nullable();
			$table->json('metadata')->nullable();
			$table->string('ip', 45)->nullable();
			$table->text('user_agent')->nullable();
			$table->timestamps();

			$table->index(['entity_type', 'entity_id']);
			$table->index(['actor_id', 'action']);
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('audit_logs');
	}
};


