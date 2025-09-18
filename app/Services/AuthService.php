<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AuthService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = env('AUTH_SERVICE_URL', 'http://fitnease-auth');
    }

    public function getUserProfile($userId, $token)
    {
        try {
            Log::info('Getting user profile from auth service', [
                'service' => 'fitnease-media',
                'user_id' => $userId,
                'auth_service_url' => $this->baseUrl
            ]);

            $response = Http::timeout(30)->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ])->get($this->baseUrl . '/api/auth/user-profile/' . $userId);

            if ($response->successful()) {
                Log::info('User profile retrieved successfully', [
                    'service' => 'fitnease-media',
                    'user_id' => $userId
                ]);

                return $response->json();
            }

            Log::warning('Failed to get user profile', [
                'service' => 'fitnease-media',
                'status' => $response->status(),
                'response_body' => $response->body()
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Auth service communication error', [
                'service' => 'fitnease-media',
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'auth_service_url' => $this->baseUrl
            ]);

            return null;
        }
    }

    public function validateUserAccess($userId, $token)
    {
        try {
            Log::info('Validating user access via auth service', [
                'service' => 'fitnease-media',
                'user_id' => $userId,
                'auth_service_url' => $this->baseUrl
            ]);

            $response = Http::timeout(30)->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ])->get($this->baseUrl . '/api/auth/validate');

            if ($response->successful()) {
                Log::info('User access validated successfully', [
                    'service' => 'fitnease-media',
                    'user_id' => $userId
                ]);

                return $response->json();
            }

            Log::warning('Failed to validate user access', [
                'service' => 'fitnease-media',
                'status' => $response->status(),
                'response_body' => $response->body()
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Auth service communication error', [
                'service' => 'fitnease-media',
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'auth_service_url' => $this->baseUrl
            ]);

            return null;
        }
    }
}