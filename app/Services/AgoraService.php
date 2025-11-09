<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Agora Video Service Integration
 * Handles RTC token generation for video conferencing
 *
 * Note: Agora uses a simpler channel-based approach compared to HMS
 * Channels are identified by string names (we use session IDs)
 */
class AgoraService
{
    private string $appId;
    private string $appCertificate;
    private int $tokenExpirationSeconds;

    public function __construct()
    {
        $this->appId = env('AGORA_APP_ID', '');
        $this->appCertificate = env('AGORA_APP_CERTIFICATE', '');
        $this->tokenExpirationSeconds = 7200; // 2 hours (enough for a workout session)

        if (empty($this->appId) || empty($this->appCertificate)) {
            Log::warning('Agora credentials not configured. Video conferencing will not work.');
        }
    }

    /**
     * Generate RTC token for user to join a channel
     * This is what the client receives to authenticate with Agora
     *
     * @param string $channelName - The channel name (we use session IDs)
     * @param int $uid - User's numeric ID
     * @param string $role - 'publisher' or 'subscriber' (we use publisher for all)
     * @return string - The RTC token
     */
    public function generateRtcToken(
        string $channelName,
        int $uid,
        string $role = 'publisher'
    ): string {
        try {
            // Agora role: 1 = publisher (can send/receive), 2 = subscriber (receive only)
            $agoraRole = $role === 'publisher' ? 1 : 2;

            // Calculate expiration timestamp
            $currentTimestamp = time();
            $privilegeExpiredTs = $currentTimestamp + $this->tokenExpirationSeconds;

            // Build the RTC token manually using Agora's algorithm
            // For simplicity, we'll use a basic implementation
            // In production, you should use the official Agora PHP SDK
            $token = $this->buildToken(
                $this->appId,
                $this->appCertificate,
                $channelName,
                $uid,
                $agoraRole,
                $privilegeExpiredTs
            );

            Log::info('[Agora] RTC token generated', [
                'channel' => $channelName,
                'uid' => $uid,
                'role' => $role,
                'expires_at' => date('Y-m-d H:i:s', $privilegeExpiredTs)
            ]);

            return $token;

        } catch (\Exception $e) {
            Log::error('[Agora] Failed to generate RTC token', [
                'error' => $e->getMessage(),
                'channel' => $channelName,
                'uid' => $uid
            ]);
            throw $e;
        }
    }

    /**
     * Build Agora RTC token using AccessToken builder
     *
     * Note: For production, consider installing the official SDK:
     * composer require agora/rtc-token-builder
     *
     * This implementation uses Agora's AccessToken format
     */
    private function buildToken(
        string $appId,
        string $appCertificate,
        string $channelName,
        int $uid,
        int $role,
        int $expireTimestamp
    ): string {
        $salt = random_int(1, 99999999);
        $ts = time();

        // Build the message to sign
        $message = $this->packMessage([
            $appId,
            $ts,
            $salt,
            $channelName,
            $uid,
            $expireTimestamp,
            $role
        ]);

        // Create signature
        $signature = hash_hmac('sha256', $message, $appCertificate, true);

        // Build the token content
        $content = $this->packTokenContent($appId, $ts, $salt, $signature);

        // Encode to base64 and return with version prefix
        return '006' . base64_encode($content);
    }

    /**
     * Pack message for signing
     */
    private function packMessage(array $parts): string
    {
        return implode(':', $parts);
    }

    /**
     * Pack token content for encoding
     */
    private function packTokenContent(string $appId, int $ts, int $salt, string $signature): string
    {
        // Simple binary packing - appId + timestamp + salt + signature
        return pack('a32Na*Na*',
            str_pad($appId, 32, "\0"),
            $ts,
            pack('N', $salt),
            strlen($signature),
            $signature
        );
    }

    /**
     * Check if service is properly configured
     */
    public function isConfigured(): bool
    {
        return !empty($this->appId) && !empty($this->appCertificate);
    }

    /**
     * Get service status for health checks
     */
    public function getStatus(): array
    {
        return [
            'configured' => $this->isConfigured(),
            'app_id' => !empty($this->appId) ? substr($this->appId, 0, 8) . '...' : null,
            'token_expiration_seconds' => $this->tokenExpirationSeconds,
        ];
    }

    /**
     * Get App ID (needed by client for initialization)
     * Only return this if service is properly configured
     */
    public function getAppId(): ?string
    {
        return $this->isConfigured() ? $this->appId : null;
    }

    /**
     * Validate channel name format
     * Agora requires channel names to be strings with specific constraints
     */
    public function validateChannelName(string $channelName): bool
    {
        // Channel name must be ASCII letters, numbers, and limited special chars
        // Maximum 64 characters
        return strlen($channelName) <= 64 &&
               preg_match('/^[a-zA-Z0-9\-_]+$/', $channelName);
    }

    /**
     * Note: Unlike HMS, Agora doesn't have a REST API for room management
     * Channels are created automatically when the first user joins
     * This simplifies our architecture - we only need token generation
     *
     * Room state tracking is still done in our database via VideoRoom model
     */
}
