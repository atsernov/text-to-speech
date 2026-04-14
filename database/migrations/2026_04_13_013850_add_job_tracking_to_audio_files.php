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
        Schema::table('audio_files', function (Blueprint $table) {
            // Add job tracking
            $table->string('job_id')->nullable()->index()->after('id');
            // Set 'done' as the default for existing completed records
            $table->string('status')->default('done')->after('job_id');
            // filename and audio_url are now nullable — the record is created before the job finishes
            $table->string('filename')->nullable()->change();
            $table->string('audio_url')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audio_files', function (Blueprint $table) {
            $table->dropColumn(['job_id', 'status']);
            $table->string('filename')->nullable(false)->change();
            $table->string('audio_url')->nullable(false)->change();
        });
    }
};
