<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ValidateApiToken
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['error' => 'No token provided'], 401);
        }

        try {
            $authServiceUrl = env('AUTH_SERVICE_URL');
            $response = Http::timeout(10)->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])->get($authServiceUrl . '/api/auth/user');

            if ($response->successful()) {
                $userData = $response->json();
                $request->attributes->set('user', $userData);
                $request->attributes->set('user_id', $userData['user_id']);
                return $next($request);
            } else {
                Log::warning('Auth service returned error', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return response()->json(['error' => 'Invalid token'], 401);
            }
        } catch (\Exception $e) {
            Log::error('Failed to validate token with auth service', [
                'error' => $e->getMessage(),
                'token' => substr($token, 0, 10) . '...'
            ]);
            return response()->json(['error' => 'Authentication service unavailable'], 503);
        }
    }
}