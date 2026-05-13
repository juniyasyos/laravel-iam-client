<?php

namespace Juniyasyos\IamClient\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Juniyasyos\IamClient\Services\UserApplicationsService;
use Juniyasyos\IamClient\Support\IamConfig;

/**
 * Verify IAM access token on every request when enabled via config.
 * - Respects config('iam.verify_each_request') to enable/disable the check.
 * - Does NOT log out on transient verification errors (network/timeouts).
 */
class VerifyIamToken
{
    public function handle(Request $request, Closure $next)
    {
        // Only run token verification if IAM is actually enabled
        if (! config('iam.enabled', false)) {
            return $next($request);
        }

        if (! config('iam.verify_each_request', true)) {
            return $next($request);
        }

        $accessToken = $request->session()->get('iam.access_token')
            ?? $request->session()->get('iam.access_token_backup');

        if (empty($accessToken)) {
            // Security fix: If user is authenticated but IAM token is missing,
            // ALWAYS logout to avoid stale web-session without valid IAM token.
            // Regardless of whether iam.sub exists, token is required for IAM-authenticated users.
            if (Auth::check()) {
                $userId = Auth::id();

                Log::warning('IamClient::VerifyIamToken - authenticated session without IAM token; clearing session', [
                    'session_id' => $request->session()->getId(),
                    'user_id' => $userId,
                    'iam_sub' => $request->session()->get('iam.sub'),
                    'reason' => 'Token missing - potential session inconsistency',
                ]);

                // Clear application cache
                UserApplicationsService::clearUserAppCache($userId);
                UserApplicationsService::clearSessionAppCache();

                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                $request->session()->forget('iam');

                if ($request->wantsJson() || $request->ajax()) {
                    return response()->json(['message' => 'Session expired, please login again.'], 401);
                }

                $loginRoute = IamConfig::loginRouteName(config('iam.guard', 'web'));

                if (\Illuminate\Support\Facades\Route::has($loginRoute)) {
                    return redirect()->route($loginRoute)->with('warning', 'Session expired, please login again.');
                }

                return redirect()->to(config('iam.login_route', '/sso/login'))->with('warning', 'Session expired, please login again.');
            }

            return $next($request);
        }

        // Prefer local JWT verification to avoid network roundtrips
        try {
            $payload = \Juniyasyos\IamClient\Support\TokenValidator::decode($accessToken);

            // keep session payload in sync
            $request->session()->put('iam.payload', (array) $payload);

            // Ensure iam.sub is ALWAYS present when token exists (consistency check)
            $sub = $payload->sub ?? null;
            if ($sub) {
                $request->session()->put('iam.sub', $sub);
            } else {
                // Token decoded but missing 'sub' claim - this is invalid
                throw new \Exception('Token missing required "sub" (subject) claim');
            }
        } catch (\Throwable $e) {
            $message = strtolower($e->getMessage());

            Log::warning('IamClient::VerifyIamToken - token invalid, attempting silent refresh if expired', [
                'error' => $e->getMessage(),
                'session_id' => $request->session()->getId(),
            ]);

            $refreshedToken = null;
            if (str_contains($message, 'expired')) {
                // Only attempt refresh when token is explicitly expired.
                $refreshedToken = $this->attemptSilentRefresh($accessToken);
            }

            if ($refreshedToken) {
                // Update session with new token
                $request->session()->put('iam.access_token', $refreshedToken);

                try {
                    $payload = \Juniyasyos\IamClient\Support\TokenValidator::decode($refreshedToken);
                    $request->session()->put('iam.payload', (array) $payload);

                    // Ensure iam.sub is ALWAYS present when token exists
                    $sub = $payload->sub ?? null;
                    if ($sub) {
                        $request->session()->put('iam.sub', $sub);
                    } else {
                        throw new \Exception('Refreshed token missing required "sub" (subject) claim');
                    }

                    // Update token expiry/session metadata so EnforceSessionTimeout sees the refreshed token
                    try {
                        $expiryInfo = \Juniyasyos\IamClient\Support\TokenExpiryManager::extractExpiry($refreshedToken);
                        if ($expiryInfo) {
                            $request->session()->put('iam.token_exp_at', $expiryInfo['exp_at']);
                            $request->session()->put('iam.token_expires_seconds', $expiryInfo['remaining_seconds']);
                            $request->session()->put('iam.token_expires_minutes', $expiryInfo['remaining_minutes']);

                            if (config('iam.sync_session_lifetime', true)) {
                                $sessionLifetime = \Juniyasyos\IamClient\Support\TokenExpiryManager::calculateSessionLifetime($refreshedToken, (int) config('iam.session_lifetime_buffer', 2));
                                if ($sessionLifetime) {
                                    $request->session()->put('iam.session_lifetime', $sessionLifetime);
                                }
                            }
                        }
                    } catch (\Throwable $metaErr) {
                        Log::debug('IamClient::VerifyIamToken - failed to update token expiry metadata after refresh', ['error' => $metaErr->getMessage()]);
                    }

                    Log::info('IamClient::VerifyIamToken - silent token refresh successful', [
                        'session_id' => $request->session()->getId(),
                    ]);

                    return $next($request);
                } catch (\Throwable $decodeErr) {
                    Log::warning('IamClient::VerifyIamToken - refreshed token decode failed', [
                        'error' => $decodeErr->getMessage(),
                    ]);
                }
            }

            // If refresh was not attempted or failed, logout and redirect
            Log::warning('IamClient::VerifyIamToken - silent refresh failed or skipped, clearing session', [
                'error' => $e->getMessage(),
                'session_id' => $request->session()->getId(),
            ]);

            $userId = Auth::id();
            UserApplicationsService::clearUserAppCache($userId);
            UserApplicationsService::clearSessionAppCache();

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $request->session()->forget('iam');

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['message' => 'Session expired, please login again.'], 401);
            }

            $loginRoute = IamConfig::loginRouteName(config('iam.guard', 'web'));

            if (\Illuminate\Support\Facades\Route::has($loginRoute)) {
                return redirect()->route($loginRoute)->with('warning', 'Session expired, please login again.');
            }

            return redirect()->to(config('iam.login_route', '/sso/login'))->with('warning', 'Session expired, please login again.');
        }

        // Remote verification (serves on-server logout expiration/revoke)
        if (config('iam.verify_remote_each_request', true)) {
            // Cache remote verification per token to avoid remote call on every request.
            $verifyCacheKey = 'iam:verify:token:' . sha1($accessToken);
            $cachedOk = Cache::get($verifyCacheKey);

            if ($cachedOk !== true) {
                try {
                    $verifyResponse = Http::timeout(4)->post(IamConfig::verifyEndpoint(), [
                        'token' => $accessToken,
                        'include_user_data' => false,
                    ]);

                    if (! $verifyResponse->successful()) {
                        throw new \Exception('Remote verify returned non-200');
                    }

                    // Cache success briefly to reduce roundtrips. TTL configurable via env/config if needed.
                    Cache::put($verifyCacheKey, true, config('iam.verify_cache_ttl', 60));
                } catch (\Throwable $remoteException) {
                    Log::warning('IamClient::VerifyIamToken - remote verify failure, clearing session', [
                        'error' => $remoteException->getMessage(),
                        'session_id' => $request->session()->getId(),
                    ]);

                    Auth::logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                    $request->session()->forget('iam');

                    if ($request->wantsJson() || $request->ajax()) {
                        return response()->json(['message' => 'Session invalidated by IAM, please login again.'], 401);
                    }

                    $loginRoute = IamConfig::loginRouteName(config('iam.guard', 'web'));

                    if (\Illuminate\Support\Facades\Route::has($loginRoute)) {
                        return redirect()->route($loginRoute)->with('warning', 'Session invalidated by IAM, please login again.');
                    }

                    return redirect()->to(config('iam.login_route', '/sso/login'))->with('warning', 'Session invalidated by IAM, please login again.');
                }
            }
        }

        return $next($request);
    }

    /**
     * Attempt to silently refresh the expired token.
     * Returns refreshed token on success, null on failure.
     */
    private function attemptSilentRefresh(?string $expiredToken): ?string
    {
        if (empty($expiredToken)) {
            return null;
        }

        try {
            $response = Http::timeout(3)->post(IamConfig::refreshTokenEndpoint(), [
                'token' => $expiredToken,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['access_token'])) {
                    return $data['access_token'];
                }
            }
        } catch (\Throwable $e) {
            Log::debug('IamClient::VerifyIamToken - silent refresh request failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }
}
