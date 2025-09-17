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
        Schema::create('media_files', function (Blueprint $table) {
            $table->id('media_file_id');
            $table->string('file_name', 255);
            $table->string('original_file_name', 255);
            $table->string('file_path', 500);
            $table->enum('file_type', ['video', 'audio', 'image', 'document']);
            $table->bigInteger('file_size_bytes');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('uploaded_by');
            $table->string('entity_type', 50)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->boolean('is_public')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('cdn_url', 500)->nullable();
            $table->string('thumbnail_path', 500)->nullable();
            $table->enum('upload_status', ['uploading', 'processing', 'ready', 'failed'])->default('uploading');
            $table->timestamp('uploaded_at')->useCurrent();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id', 'is_active'], 'idx_media_files_entity');
            $table->index(['uploaded_by', 'uploaded_at'], 'idx_media_files_uploader');
            $table->index(['cdn_url', 'upload_status'], 'idx_media_files_cdn');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_files');
    }
};
