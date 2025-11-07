<?php

namespace App\Services;

use GuzzleHttp\Client;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Log;

/**
 * 100ms (HMS) Video Service Integration
 * Handles token generation and room management for video conferencing
 */
class HMSService
{
    private string $accessKey;
    private string $appSecret;
    private ?string $templateId;
    private Client $httpClient;
    private const HMS_API_BASE = 'https://api.100ms.live/v2';

    public function __construct()
    {
        $this->accessKey = env('HMS_APP_ACCESS_KEY', '');
        $this->appSecret = env('HMS_APP_SECRET', '');
        $this->templateId = env('HMS_TEMPLATE_ID', null);

        $this->httpClient = new Client([
            'base_uri' => self::HMS_API_BASE,
            'timeout' => 10,
            'headers' => [
                'Content-Type' => 'application/json',
            ]
        ]);

        if (empty($this->accessKey) || empty($this->appSecret)) {
            Log::warning('HMS credentials not configured. Video conferencing will not work.');
        }
    }

    /**
     * Generate management token for server-side API calls
     * NEVER expose this to the client!
     */
    private function generateManagementToken(): string
    {
        $now = time();
        $payload = [
            'access_key' => $this->accessKey,
            'type' => 'management',
            'version' => 2,
            'iat' => $now,
            'exp' => $now + 86400, // 24 hours
        ];

        return JWT::encode($payload, $this->appSecret, 'HS256');
    }

    /**
     * Generate auth token for user to join a room
     * This is what the client receives
     */
    public function generateAuthToken(
        string $roomId,
        int $userId,
        string $username,
        string $role = 'guest'
    ): string {
        $now = time();
        $payload = [
            'access_key' => $this->accessKey,
            'room_id' => $roomId,
            'user_id' => (string) $userId,
            'role' => $role,
            'type' => 'app',
            'version' => 2,
            'iat' => $now,
            'exp' => $now + 7200, // 2 hours (enough for a workout session)
            'jti' => uniqid(),
        ];

        return JWT::encode($payload, $this->appSecret, 'HS256');
    }

    /**
     * Create a new HMS room
     */
    public function createRoom(string $name, ?string $description = null): ?array
    {
        try {
            $managementToken = $this->generateManagementToken();

            $payload = [
                'name' => $name,
                'description' => $description ?? "FitNEase group workout session",
                'recording_info' => [
                    'enabled' => false, // Can be enabled per request
                ],
            ];

            // Add template if configured
            if ($this->templateId) {
                $payload['template_id'] = $this->templateId;
            }

            $response = $this->httpClient->post('/rooms', [
                'headers' => [
                    'Authorization' => "Bearer {$managementToken}",
                ],
                'json' => $payload,
            ]);

            $data = json_decode($response->getBody(), true);

            Log::info('[HMS] Room created successfully', [
                'room_id' => $data['id'] ?? null,
                'name' => $name
            ]);

            return $data;

        } catch (\Exception $e) {
            Log::error('[HMS] Failed to create room', [
                'error' => $e->getMessage(),
                'name' => $name
            ]);
            return null;
        }
    }

    /**
     * Get room details
     */
    public function getRoom(string $roomId): ?array
    {
        try {
            $managementToken = $this->generateManagementToken();

            $response = $this->httpClient->get("/rooms/{$roomId}", [
                'headers' => [
                    'Authorization' => "Bearer {$managementToken}",
                ],
            ]);

            return json_decode($response->getBody(), true);

        } catch (\Exception $e) {
            Log::error('[HMS] Failed to get room', [
                'error' => $e->getMessage(),
                'room_id' => $roomId
            ]);
            return null;
        }
    }

    /**
     * Disable a room (soft delete)
     */
    public function disableRoom(string $roomId): bool
    {
        try {
            $managementToken = $this->generateManagementToken();

            $this->httpClient->post("/rooms/{$roomId}/disable", [
                'headers' => [
                    'Authorization' => "Bearer {$managementToken}",
                ],
            ]);

            Log::info('[HMS] Room disabled successfully', ['room_id' => $roomId]);
            return true;

        } catch (\Exception $e) {
            Log::error('[HMS] Failed to disable room', [
                'error' => $e->getMessage(),
                'room_id' => $roomId
            ]);
            return false;
        }
    }

    /**
     * Get active sessions in a room
     */
    public function getActiveSessions(string $roomId): ?array
    {
        try {
            $managementToken = $this->generateManagementToken();

            $response = $this->httpClient->get("/sessions", [
                'headers' => [
                    'Authorization' => "Bearer {$managementToken}",
                ],
                'query' => [
                    'room_id' => $roomId,
                    'active' => true,
                ],
            ]);

            return json_decode($response->getBody(), true);

        } catch (\Exception $e) {
            Log::error('[HMS] Failed to get active sessions', [
                'error' => $e->getMessage(),
                'room_id' => $roomId
            ]);
            return null;
        }
    }

    /**
     * End all active sessions in a room
     */
    public function endActiveSessions(string $roomId): bool
    {
        try {
            $sessions = $this->getActiveSessions($roomId);

            if (!$sessions || empty($sessions['data'])) {
                return true; // No active sessions
            }

            $managementToken = $this->generateManagementToken();

            foreach ($sessions['data'] as $session) {
                $sessionId = $session['id'];

                $this->httpClient->post("/sessions/{$sessionId}/end", [
                    'headers' => [
                        'Authorization' => "Bearer {$managementToken}",
                    ],
                    'json' => [
                        'reason' => 'Workout session completed',
                    ],
                ]);
            }

            Log::info('[HMS] Ended active sessions', [
                'room_id' => $roomId,
                'session_count' => count($sessions['data'])
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('[HMS] Failed to end active sessions', [
                'error' => $e->getMessage(),
                'room_id' => $roomId
            ]);
            return false;
        }
    }

    /**
     * Start recording for a session
     */
    public function startRecording(string $roomId): ?array
    {
        try {
            $managementToken = $this->generateManagementToken();

            $response = $this->httpClient->post("/recordings/room/{$roomId}/start", [
                'headers' => [
                    'Authorization' => "Bearer {$managementToken}",
                ],
                'json' => [
                    'meeting_url' => null, // HMS will auto-generate
                ],
            ]);

            $data = json_decode($response->getBody(), true);

            Log::info('[HMS] Recording started', [
                'room_id' => $roomId,
                'recording_id' => $data['id'] ?? null
            ]);

            return $data;

        } catch (\Exception $e) {
            Log::error('[HMS] Failed to start recording', [
                'error' => $e->getMessage(),
                'room_id' => $roomId
            ]);
            return null;
        }
    }

    /**
     * Stop recording for a session
     */
    public function stopRecording(string $roomId): bool
    {
        try {
            $managementToken = $this->generateManagementToken();

            $this->httpClient->post("/recordings/room/{$roomId}/stop", [
                'headers' => [
                    'Authorization' => "Bearer {$managementToken}",
                ],
            ]);

            Log::info('[HMS] Recording stopped', ['room_id' => $roomId]);
            return true;

        } catch (\Exception $e) {
            Log::error('[HMS] Failed to stop recording', [
                'error' => $e->getMessage(),
                'room_id' => $roomId
            ]);
            return false;
        }
    }

    /**
     * Check if service is properly configured
     */
    public function isConfigured(): bool
    {
        return !empty($this->accessKey) && !empty($this->appSecret);
    }

    /**
     * Get service status for health checks
     */
    public function getStatus(): array
    {
        return [
            'configured' => $this->isConfigured(),
            'has_template' => !empty($this->templateId),
            'api_base' => self::HMS_API_BASE,
        ];
    }
}
