<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\VideoConferenceController;
use App\Http\Controllers\StreamingController;
use App\Http\Controllers\ServiceTestController;
use App\Http\Controllers\ServiceCommunicationTestController;
use App\Http\Controllers\ServiceIntegrationDemoController;
use App\Http\Controllers\MediaServiceTestController;
use App\Http\Controllers\MediaServiceCommunicationTestController;

Route::get('/user', function (Request $request) {
    return $request->attributes->get('user');
})->middleware('auth.api');

Route::prefix('media')->middleware('auth.api')->group(function () {

    // Profile Picture Upload
    Route::post('/profile-picture', [MediaController::class, 'uploadProfilePicture'])->name('media.profile-picture.upload');

    // File Management Routes
    Route::post('/upload', [MediaController::class, 'upload'])->name('media.upload');
    Route::get('/file/{fileId}', [MediaController::class, 'show'])->name('media.file.show');
    Route::put('/file/{fileId}', [MediaController::class, 'update'])->name('media.file.update');
    Route::delete('/file/{fileId}', [MediaController::class, 'destroy'])->name('media.file.delete');
    Route::get('/download/{fileId}', [MediaController::class, 'download'])->name('media.download');
    Route::get('/thumbnail/{fileId}', [MediaController::class, 'thumbnail'])->name('media.thumbnail');
    Route::get('/search', [MediaController::class, 'search'])->name('media.search');

    // Video Management Routes
    Route::get('/videos/{exerciseId}', [VideoController::class, 'getByExercise'])->name('media.video.exercise');
    Route::get('/video/details/{videoId}', [VideoController::class, 'show'])->name('media.video.details');
    Route::post('/video/rating', [VideoController::class, 'rate'])->name('media.video.rating');
    Route::get('/videos/featured', [VideoController::class, 'getFeatured'])->name('media.videos.featured');
    Route::get('/videos/by-difficulty/{level}', [VideoController::class, 'getByDifficulty'])->name('media.videos.difficulty');
    Route::get('/videos/by-type/{type}', [VideoController::class, 'getByType'])->name('media.videos.type');
    Route::post('/video/content', [VideoController::class, 'store'])->name('media.video.store');

    // ML-Powered Personalized Recommendations (as specified in the document)
    Route::get('/videos/recommendations/{userId}', [VideoController::class, 'getPersonalizedRecommendations'])->name('media.videos.recommendations');

    // Streaming Routes
    Route::get('/stream/{videoId}', [StreamingController::class, 'streamVideo'])->name('media.stream.video');
    Route::get('/stream/audio/{audioId}', [StreamingController::class, 'streamAudio'])->name('media.stream.audio');
    Route::get('/streaming/manifest/{videoId}', [StreamingController::class, 'generateStreamingManifest'])->name('media.streaming.manifest');
    Route::get('/streaming/token/{mediaFileId}', [StreamingController::class, 'getStreamingToken'])->name('media.streaming.token');

});

// Video Conferencing Routes (100ms Integration)
// Real-time video calls for group workout sessions
Route::prefix('video')->middleware('auth.api')->group(function () {

    // Room Management
    Route::post('/rooms/create', [VideoConferenceController::class, 'createRoom'])->name('video.room.create');
    Route::get('/rooms/{session_id}', [VideoConferenceController::class, 'getRoom'])->name('video.room.get');
    Route::delete('/rooms/{session_id}', [VideoConferenceController::class, 'closeRoom'])->name('video.room.close');

    // Join Token Generation
    Route::post('/rooms/{session_id}/token', [VideoConferenceController::class, 'getJoinToken'])->name('video.token.get');

    // Participant Management
    Route::get('/rooms/{session_id}/participants', [VideoConferenceController::class, 'getParticipants'])->name('video.participants.list');
    Route::delete('/rooms/{session_id}/participants/{user_id}', [VideoConferenceController::class, 'leaveRoom'])->name('video.participants.leave');

    // Recording (Optional)
    Route::post('/rooms/{session_id}/recording/start', [VideoConferenceController::class, 'startRecording'])->name('video.recording.start');
    Route::post('/rooms/{session_id}/recording/stop', [VideoConferenceController::class, 'stopRecording'])->name('video.recording.stop');

    // Service Status
    Route::get('/status', [VideoConferenceController::class, 'getStatus'])->name('video.status');
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

// Add a simple health check for the root route too
Route::get('/', function () {
    return response()->json([
        'message' => 'FitNEase Media Service',
        'status' => 'running',
        'endpoints' => [
            'health' => '/health',
            'upload' => '/media/upload',
            'videos' => '/media/videos/{exerciseId}',
            'streaming' => '/media/stream/{videoId}',
            'service_test' => '/test-services',
            'service_communication_tests' => [
                'content_service' => '/service-test/content/videos/{exerciseId}',
                'ml_service' => '/service-test/ml/recommendations/{userId}',
                'mobile_app' => '/service-test/mobile/stream/{videoId}',
                'logs' => '/service-test/logs'
            ]
        ]
    ]);
});

// Service Communication Test Routes
Route::get('/test-services', [ServiceTestController::class, 'testServiceCommunication'])
    ->middleware('auth.api')
    ->name('test.services');

// Service Communication Monitoring Routes (for testing which services call media service)
Route::prefix('service-test')->middleware('auth.api')->group(function () {
    // Content Service Integration Test
    Route::get('/content/videos/{exerciseId}', [ServiceCommunicationTestController::class, 'getExerciseVideosForContentService'])
        ->name('test.content.videos');

    // ML Service Integration Test
    Route::get('/ml/recommendations/{userId}', [ServiceCommunicationTestController::class, 'getPersonalizedRecommendationsForMLService'])
        ->name('test.ml.recommendations');

    // Mobile App Integration Test
    Route::get('/mobile/stream/{videoId}', [ServiceCommunicationTestController::class, 'streamVideoForMobileApp'])
        ->name('test.mobile.stream');

    // Communication Monitoring Dashboard
    Route::get('/logs', [ServiceCommunicationTestController::class, 'getServiceCommunicationLogs'])
        ->name('test.communication.logs');
});

// Service Integration Demo Routes (No authentication required for demonstration)
Route::prefix('demo')->group(function () {
    // Content Service Integration Demo
    Route::get('/content-service/videos/{exerciseId}', [ServiceIntegrationDemoController::class, 'demoContentServiceCall'])
        ->name('demo.content.videos');

    // ML Service Integration Demo
    Route::get('/ml-service/recommendations/{userId}', [ServiceIntegrationDemoController::class, 'demoMLServiceCall'])
        ->name('demo.ml.recommendations');

    // Mobile App Integration Demo
    Route::get('/mobile-app/stream/{videoId}', [ServiceIntegrationDemoController::class, 'demoMobileAppStreaming'])
        ->name('demo.mobile.stream');

    // Service Integration Overview
    Route::get('/integrations', [ServiceIntegrationDemoController::class, 'getServiceIntegrationOverview'])
        ->name('demo.integrations');
});

// Comprehensive service testing routes - for validating inter-service communication
Route::middleware('auth.api')->prefix('service-tests')->group(function () {
    Route::get('/auth', [MediaServiceTestController::class, 'testAuthService']);
    Route::get('/content', [MediaServiceTestController::class, 'testContentService']);
    Route::get('/engagement', [MediaServiceTestController::class, 'testEngagementService']);
    Route::get('/all', [MediaServiceTestController::class, 'testAllServices']);

    Route::get('/connectivity', [MediaServiceCommunicationTestController::class, 'testServiceConnectivity']);
    Route::get('/token-validation', [MediaServiceCommunicationTestController::class, 'testMediaTokenValidation']);
    Route::get('/integration', [MediaServiceCommunicationTestController::class, 'testServiceIntegration']);
});
