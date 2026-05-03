<?php

namespace Juniyasyos\IamClient\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Juniyasyos\IamClient\Support\IamConfig;
use Juniyasyos\IamClient\Support\TokenExpiryManager;

/**
 * Monitor and enforce session timeout based on token expiry.
 * 
 * When sync_session_lifetime is enabled, this middleware:
 * 1. Checks if token expiry info is stored in session
 * 2. Compares current time with token expiry
 * 3. Logs warning if approaching expiry
 * 4. Forces logout if session has outlived token expiry
 * 5. Provides debug info for troubleshooting
 */
class EnforceSessionTimeout
{
    public function handle(Request $request, Closure $next)
    {
        // Only run if IAM is actually enabled
        if (! config('iam.enabled', false)) {
            return $next($request);
        }

        // Only run if session has IAM token expiry info
        $tokenExpAt = session('iam.token_exp_at');
        $tokenExpiresSeconds = session('iam.token_expires_seconds');
        $sessionLifetime = session('iam.session_lifetime');

        if (empty($tokenExpAt) || empty($tokenExpiresSeconds)) {
            // No token expiry info, proceed normally
            return $next($request);
        }

        $now = now();
        $tokenExpires = \Carbon\Carbon::parse($tokenExpAt);
        $isExpired = $now->isAfter($tokenExpires);

        if ($isExpired) {
            // Token has expired, force logout
            $userId = auth()->id();

            Log::warning('EnforceSessionTimeout: Token expired, logging out user', [
                'user_id' => $userId,
                'token_exp_at' => $tokenExpAt,
                'now' => $now->toIso8601String(),
                'session_id' => $request->session()->getId(),
            ]);

            // Notify IAM that token expired on client side (best-effort)
            // This allows IAM to trigger logout chain for other clients
            if ($userId) {
                try {
                    $iamBaseUrl = IamConfig::baseUrl();
                    // Convert token expiry to UTC Z format for API
                    $expiredAtUtc = \Carbon\Carbon::parse($tokenExpAt)
                        ->setTimezone('UTC')
                        ->format('Y-m-d\TH:i:s\Z');

                    $response = \Illuminate\Support\Facades\Http::timeout(3)->post(
                        $iamBaseUrl . '/api/iam/notify-token-expired',
                        [
                            'user_id' => $userId,
                            'app_key' => config('iam.app_key'),
                            'expired_at' => $expiredAtUtc,
                        ]
                    );
                    Log::info('EnforceSessionTimeout: Notified IAM of token expiry', ['status' => $response->status()]);
                } catch (\Throwable $e) {
                    Log::warning('EnforceSessionTimeout: Failed to notify IAM of token expiry', ['error' => $e->getMessage()]);
                }
            }

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $request->session()->forget('iam');

            if ($request->wantsJson()) {
                return response()->json(['message' => 'Token has expired, please login again.'], 401);
            }

            $loginRoute = IamConfig::loginRouteName(config('iam.guard', 'web'));

            if (\Illuminate\Support\Facades\Route::has($loginRoute)) {
                return redirect()->route($loginRoute)->with('warning', 'Your session expired. Please login again.');
            }

            return redirect()->to(config('iam.login_route', '/sso/login'))->with('warning', 'Your session expired. Please login again.');
        }

        // Check if approaching expiry (within 5 minutes)
        $minutesRemaining = (int) ceil($tokenExpiresSeconds / 60);
        if ($minutesRemaining <= 5 && $minutesRemaining > 0) {
            Log::notice('EnforceSessionTimeout: Token approaching expiry', [
                'token_exp_at' => $tokenExpAt,
                'minutes_remaining' => $minutesRemaining,
                'session_id' => $request->session()->getId(),
            ]);

            // Store in session for frontend to display warning if needed
            session()->put('iam.token_expiring_soon', true);
            session()->put('iam.token_minutes_remaining', $minutesRemaining);
        } else {
            session()->forget('iam.token_expiring_soon');
        }

        // Update remaining time for next request
        $issuedAt = session('iam.token_issued_at');
        if (!$issuedAt) {
            // First time, set issued_at
            session()->put('iam.token_issued_at', now()->toIso8601String());
        }

        return $next($request);
    }
}
