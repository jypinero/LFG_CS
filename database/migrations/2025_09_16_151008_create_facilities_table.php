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
        Schema::create('facilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained('venues')->onDelete('cascade');
            $table->decimal('price_per_hr', 8, 2);
            $table->enum('type', [
                'stadium', 'arena', 'sport complex', 'gymnasium', 'soccer dom', 'swimming pool',
                'tennis court', 'track and field complex', 'basketball court', 'volleyball court',
                'multipurpose hall', 'fitness center', 'recreational center', 'golf course',
                'archery range', 'shooting range'
            ]);
            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('facilities');
    }
};
