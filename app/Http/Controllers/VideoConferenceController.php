<?php

namespace App\Http\Controllers;

use App\Models\VideoRoom;
use App\Models\VideoParticipant;
use App\Models\VideoRecording;
use App\Services\AgoraService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

/**
 * Handles real-time video conferencing for group workout sessions
 * Integrates with Agora RTC for video calling functionality
 */
class VideoConferenceController extends Controller
{
    private AgoraService $agoraService;

    public function __construct(AgoraService $agoraService)
    {
        $this->agoraService = $agoraService;
    }

    /**
     * Create or get existing video room for a workout session
     * POST /api/video/rooms/create
     */
    public function createRoom(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'session_id' => 'required|string',
            'session_name' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Check if Agora is configured
            if (!$this->agoraService->isConfigured()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Video conferencing service not configured'
                ], 503);
            }

            $sessionId = $request->session_id;

            // Validate channel name format for Agora
            if (!$this->agoraService->validateChannelName($sessionId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid session ID format for video channel'
                ], 422);
            }

            // Check if room already exists and is active
            $existingRoom = VideoRoom::where('session_id', $sessionId)
                ->active()
                ->first();

            if ($existingRoom) {
                Log::info('[VideoConference] Room already exists', [
                    'session_id' => $sessionId,
                    'room_id' => $existingRoom->id
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Room already exists',
                    'data' => [
                        'room_id' => $existingRoom->id,
                        'session_id' => $existingRoom->session_id,
                        'channel_name' => $existingRoom->session_id,
                        'app_id' => $this->agoraService->getAppId(),
                        'status' => $existingRoom->status,
                        'active_participants' => $existingRoom->activeParticipants()->count()
                    ]
                ]);
            }

            // For Agora, we don't need to create a room on their server
            // Channels are created automatically when first user joins
            // We only track it in our database
            $videoRoom = VideoRoom::create([
                'session_id' => $sessionId,
                'hms_room_id' => $sessionId, // Use session_id as channel name for Agora
                'status' => 'active'
            ]);

            Log::info('[VideoConference] Room created successfully', [
                'session_id' => $sessionId,
                'room_id' => $videoRoom->id,
                'channel_name' => $sessionId
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Video room created successfully',
                'data' => [
                    'room_id' => $videoRoom->id,
                    'session_id' => $videoRoom->session_id,
                    'channel_name' => $videoRoom->session_id,
                    'app_id' => $this->agoraService->getAppId(),
                    'status' => $videoRoom->status,
                    'active_participants' => 0
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('[VideoConference] Failed to create room', [
                'error' => $e->getMessage(),
                'session_id' => $request->session_id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error creating video room',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate join token for a user to join video room
     * POST /api/video/rooms/{session_id}/token
     */
    public function getJoinToken(Request $request, string $sessionId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer',
            'username' => 'required|string|max:100',
            'role' => 'nullable|in:host,guest'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Get video room
            $room = VideoRoom::where('session_id', $sessionId)
                ->active()
                ->first();

            if (!$room) {
                return response()->json([
                    'success' => false,
                    'message' => 'Video room not found or closed'
                ], 404);
            }

            // Generate Agora RTC token
            $userId = $request->user_id;
            $username = $request->username;
            $role = $request->input('role', 'guest');

            // For Agora: all users are publishers (can send and receive)
            $agoraRole = 'publisher';

            $rtcToken = $this->agoraService->generateRtcToken(
                $sessionId, // Channel name
                $userId,    // User UID
                $agoraRole
            );

            // Track participant joining
            VideoParticipant::create([
                'room_id' => $room->id,
                'user_id' => $userId,
                'joined_at' => now()
            ]);

            Log::info('[VideoConference] Join token generated', [
                'session_id' => $sessionId,
                'user_id' => $userId,
                'role' => $role
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Join token generated successfully',
                'data' => [
                    'auth_token' => $rtcToken,
                    'room_id' => $room->id,
                    'channel_name' => $sessionId,
                    'app_id' => $this->agoraService->getAppId(),
                    'user_id' => $userId,
                    'role' => $role
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('[VideoConference] Failed to generate join token', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'user_id' => $request->user_id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error generating join token',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get video room details
     * GET /api/video/rooms/{session_id}
     */
    public function getRoom(string $sessionId): JsonResponse
    {
        try {
            $room = VideoRoom::where('session_id', $sessionId)
                ->with(['activeParticipants', 'recordings'])
                ->first();

            if (!$room) {
                return response()->json([
                    'success' => false,
                    'message' => 'Video room not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'room_id' => $room->id,
                    'session_id' => $room->session_id,
                    'hms_room_id' => $room->hms_room_id,
                    'status' => $room->status,
                    'active_participants' => $room->activeParticipants->map(function ($p) {
                        return [
                            'user_id' => $p->user_id,
                            'joined_at' => $p->joined_at->toISOString()
                        ];
                    }),
                    'total_participants' => $room->participants()->count(),
                    'recordings' => $room->recordings()->ready()->get()->map(function ($r) {
                        return [
                            'recording_id' => $r->id,
                            'url' => $r->recording_url,
                            'duration' => $r->duration_formatted,
                            'size_mb' => $r->size_mb
                        ];
                    }),
                    'created_at' => $room->created_at->toISOString(),
                    'closed_at' => $room->closed_at?->toISOString()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('[VideoConference] Failed to get room', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error retrieving video room',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get participants in a video room
     * GET /api/video/rooms/{session_id}/participants
     */
    public function getParticipants(string $sessionId): JsonResponse
    {
        try {
            $room = VideoRoom::where('session_id', $sessionId)->first();

            if (!$room) {
                return response()->json([
                    'success' => false,
                    'message' => 'Video room not found'
                ], 404);
            }

            $participants = $room->participants()->get()->map(function ($p) {
                return [
                    'user_id' => $p->user_id,
                    'joined_at' => $p->joined_at->toISOString(),
                    'left_at' => $p->left_at?->toISOString(),
                    'duration' => $p->duration_formatted,
                    'is_active' => $p->isActive()
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'participants' => $participants,
                    'total' => $participants->count(),
                    'active' => $participants->where('is_active', true)->count()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('[VideoConference] Failed to get participants', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error retrieving participants',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark participant as left
     * DELETE /api/video/rooms/{session_id}/participants/{user_id}
     */
    public function leaveRoom(string $sessionId, int $userId): JsonResponse
    {
        try {
            $room = VideoRoom::where('session_id', $sessionId)->first();

            if (!$room) {
                return response()->json([
                    'success' => false,
                    'message' => 'Video room not found'
                ], 404);
            }

            $participant = $room->participants()
                ->where('user_id', $userId)
                ->active()
                ->first();

            if (!$participant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Participant not found or already left'
                ], 404);
            }

            $participant->markAsLeft();

            Log::info('[VideoConference] Participant left', [
                'session_id' => $sessionId,
                'user_id' => $userId,
                'duration' => $participant->duration_formatted
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Participant marked as left',
                'data' => [
                    'user_id' => $userId,
                    'duration' => $participant->duration_formatted,
                    'duration_seconds' => $participant->duration_seconds
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('[VideoConference] Failed to mark participant as left', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'user_id' => $userId
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error marking participant as left',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Close video room (end session)
     * DELETE /api/video/rooms/{session_id}
     */
    public function closeRoom(string $sessionId): JsonResponse
    {
        try {
            $room = VideoRoom::where('session_id', $sessionId)
                ->active()
                ->first();

            if (!$room) {
                return response()->json([
                    'success' => false,
                    'message' => 'Video room not found or already closed'
                ], 404);
            }

            // For Agora, channels are automatically closed when all users leave
            // We only need to mark the room as closed in our database
            $room->close();

            Log::info('[VideoConference] Room closed', [
                'session_id' => $sessionId,
                'room_id' => $room->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Video room closed successfully',
                'data' => [
                    'room_id' => $room->id,
                    'session_id' => $room->session_id,
                    'closed_at' => $room->closed_at->toISOString(),
                    'total_participants' => $room->participants()->count()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('[VideoConference] Failed to close room', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error closing video room',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Start recording
     * POST /api/video/rooms/{session_id}/recording/start
     */
    public function startRecording(string $sessionId): JsonResponse
    {
        try {
            $room = VideoRoom::where('session_id', $sessionId)
                ->active()
                ->first();

            if (!$room) {
                return response()->json([
                    'success' => false,
                    'message' => 'Video room not found or closed'
                ], 404);
            }

            // Start HMS recording
            $recordingData = $this->hmsService->startRecording($room->hms_room_id);

            if (!$recordingData) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to start recording'
                ], 500);
            }

            // Save to database
            $recording = VideoRecording::create([
                'room_id' => $room->id,
                'hms_recording_id' => $recordingData['id'],
                'status' => 'recording'
            ]);

            Log::info('[VideoConference] Recording started', [
                'session_id' => $sessionId,
                'recording_id' => $recording->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Recording started successfully',
                'data' => [
                    'recording_id' => $recording->id,
                    'hms_recording_id' => $recording->hms_recording_id,
                    'status' => $recording->status
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('[VideoConference] Failed to start recording', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error starting recording',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Stop recording
     * POST /api/video/rooms/{session_id}/recording/stop
     */
    public function stopRecording(string $sessionId): JsonResponse
    {
        try {
            $room = VideoRoom::where('session_id', $sessionId)->first();

            if (!$room) {
                return response()->json([
                    'success' => false,
                    'message' => 'Video room not found'
                ], 404);
            }

            $recording = $room->recordings()
                ->where('status', 'recording')
                ->first();

            if (!$recording) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active recording found'
                ], 404);
            }

            // Stop HMS recording
            $stopped = $this->hmsService->stopRecording($room->hms_room_id);

            if (!$stopped) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to stop recording'
                ], 500);
            }

            // Update status to processing
            $recording->update(['status' => 'processing']);

            Log::info('[VideoConference] Recording stopped', [
                'session_id' => $sessionId,
                'recording_id' => $recording->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Recording stopped successfully. Processing will continue in background.',
                'data' => [
                    'recording_id' => $recording->id,
                    'status' => $recording->status
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('[VideoConference] Failed to stop recording', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error stopping recording',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get service status (for health checks)
     * GET /api/video/status
     */
    public function getStatus(): JsonResponse
    {
        $status = $this->agoraService->getStatus();

        return response()->json([
            'success' => true,
            'data' => [
                'service' => 'video_conferencing',
                'provider' => 'Agora RTC',
                'configured' => $status['configured'],
                'app_id' => $status['app_id'],
                'active_rooms' => VideoRoom::active()->count(),
                'total_rooms' => VideoRoom::count(),
            ]
        ]);
    }
}
