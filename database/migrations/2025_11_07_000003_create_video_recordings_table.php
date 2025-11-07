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
        Schema::create('video_recordings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('video_rooms')->onDelete('cascade');
            $table->string('hms_recording_id')->nullable()->comment('100ms recording identifier');
            $table->text('recording_url')->nullable()->comment('URL to access the recording');
            $table->integer('duration_seconds')->nullable();
            $table->decimal('size_mb', 10, 2)->nullable();
            $table->enum('status', ['recording', 'processing', 'ready', 'failed'])->default('recording');
            $table->timestamps();

            $table->index('room_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_recordings');
    }
};
