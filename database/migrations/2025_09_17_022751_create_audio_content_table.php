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
        Schema::create('audio_content', function (Blueprint $table) {
            $table->id('audio_id');
            $table->unsignedBigInteger('media_file_id');
            $table->string('audio_title', 255);
            $table->text('audio_description')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->enum('audio_type', ['music', 'instruction', 'ambient', 'meditation'])->nullable();
            $table->enum('audio_quality', ['128kbps', '256kbps', '320kbps'])->default('256kbps');
            $table->string('genre', 50)->nullable();
            $table->boolean('is_playlist_eligible')->default(true);
            $table->integer('play_count')->default(0);
            $table->timestamps();

            $table->foreign('media_file_id')->references('media_file_id')->on('media_files')->onDelete('cascade');
            $table->index(['audio_type', 'genre'], 'idx_audio_content_type_genre');
            $table->index(['is_playlist_eligible', 'play_count'], 'idx_audio_content_playlist');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audio_content');
    }
};
