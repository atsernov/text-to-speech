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
        Schema::create('voice_samples', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->json('languages')->nullable();
            $table->string('sample_filename')->nullable(); // WAV file of the voice sample
            $table->boolean('is_active')->default(true);   // false if the API returned an error
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('voice_samples');
    }
};
