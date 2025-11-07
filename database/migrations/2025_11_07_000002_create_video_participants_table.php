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
        Schema::create('video_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('video_rooms')->onDelete('cascade');
            $table->unsignedBigInteger('user_id')->comment('User who joined the video');
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('left_at')->nullable();
            $table->integer('duration_seconds')->nullable()->comment('Calculated when user leaves');
            $table->timestamps();

            $table->index(['room_id', 'user_id']);
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_participants');
    }
};
