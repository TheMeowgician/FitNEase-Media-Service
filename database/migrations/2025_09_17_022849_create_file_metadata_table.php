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
        Schema::create('file_metadata', function (Blueprint $table) {
            $table->id('metadata_id');
            $table->unsignedBigInteger('media_file_id');
            $table->string('metadata_key', 100);
            $table->text('metadata_value')->nullable();
            $table->enum('metadata_type', ['string', 'integer', 'decimal', 'boolean', 'json'])->default('string');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('media_file_id')->references('media_file_id')->on('media_files')->onDelete('cascade');
            $table->unique(['media_file_id', 'metadata_key'], 'idx_file_metadata_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('file_metadata');
    }
};
