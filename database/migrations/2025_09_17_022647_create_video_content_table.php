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
        Schema::create('video_content', function (Blueprint $table) {
            $table->id('video_id');
            $table->unsignedBigInteger('media_file_id');
            $table->unsignedBigInteger('exercise_id')->nullable();
            $table->string('video_title', 255);
            $table->text('video_description')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->enum('video_type', ['instruction', 'form_guide', 'demonstration', 'tips', 'warm_up', 'cool_down'])->nullable();
            $table->enum('video_quality', ['480p', '720p', '1080p', '4k'])->default('720p');
            $table->string('instructor_name', 100)->nullable();
            $table->enum('difficulty_level', ['beginner', 'medium', 'expert'])->nullable();
            $table->integer('view_count')->default(0);
            $table->decimal('average_rating', 3, 2)->default(0.00);
            $table->boolean('is_featured')->default(false);
            $table->timestamps();

            $table->foreign('media_file_id')->references('media_file_id')->on('media_files')->onDelete('cascade');
            $table->index(['exercise_id', 'is_featured'], 'idx_video_content_exercise');
            $table->index(['video_type', 'difficulty_level'], 'idx_video_content_type_difficulty');
            $table->index(['view_count', 'created_at'], 'idx_video_content_views');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_content');
    }
};
