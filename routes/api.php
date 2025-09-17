<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\StreamingController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('media')->group(function () {

    // File Management Routes
    Route::post('/upload', [MediaController::class, 'upload'])->name('media.upload');
    Route::get('/file/{fileId}', [MediaController::class, 'show'])->name('media.file.show');
    Route::put('/file/{fileId}', [MediaController::class, 'update'])->name('media.file.update');
    Route::delete('/file/{fileId}', [MediaController::class, 'destroy'])->name('media.file.delete');
    Route::get('/download/{fileId}', [MediaController::class, 'download'])->name('media.download');
    Route::get('/thumbnail/{fileId}', [MediaController::class, 'thumbnail'])->name('media.thumbnail');
    Route::get('/search', [MediaController::class, 'search'])->name('media.search');

    // Video Management Routes
    Route::get('/video/{exerciseId}', [VideoController::class, 'getByExercise'])->name('media.video.exercise');
    Route::get('/video/details/{videoId}', [VideoController::class, 'show'])->name('media.video.details');
    Route::post('/video/rating', [VideoController::class, 'rate'])->name('media.video.rating');
    Route::get('/videos/featured', [VideoController::class, 'getFeatured'])->name('media.videos.featured');
    Route::get('/videos/by-difficulty/{level}', [VideoController::class, 'getByDifficulty'])->name('media.videos.difficulty');
    Route::get('/videos/by-type/{type}', [VideoController::class, 'getByType'])->name('media.videos.type');
    Route::post('/video/content', [VideoController::class, 'store'])->name('media.video.store');
    Route::get('/videos/recommendations/{userId}', [VideoController::class, 'getPersonalizedRecommendations'])->name('media.videos.recommendations');

    // Streaming Routes
    Route::get('/stream/{videoId}', [StreamingController::class, 'streamVideo'])->name('media.stream.video');
    Route::get('/stream/audio/{audioId}', [StreamingController::class, 'streamAudio'])->name('media.stream.audio');
    Route::get('/streaming/manifest/{videoId}', [StreamingController::class, 'generateStreamingManifest'])->name('media.streaming.manifest');
    Route::get('/streaming/token/{mediaFileId}', [StreamingController::class, 'getStreamingToken'])->name('media.streaming.token');

});

// Health check route
Route::get('/health', function () {
    return response()->json([
        'status' => 'healthy',
        'service' => 'fitnease-media',
        'timestamp' => now()->toISOString(),
        'version' => '1.0.0'
    ]);
})->name('health.check');
