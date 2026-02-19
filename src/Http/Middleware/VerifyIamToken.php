<?php

namespace Juniyasyos\IamClient\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
        if (! config('iam.verify_each_request', true)) {
            return $next($request);
        }

        $accessToken = $request->session()->get('iam.access_token');

        if (empty($accessToken)) {
            return $next($request);
        }

        // Prefer local JWT verification to avoid network roundtrips
        try {
            $payload = \Juniyasyos\IamClient\Support\TokenValidator::decode($accessToken);

            // keep session payload in sync
            $request->session()->put('iam.payload', (array) $payload);
            $request->session()->put('iam.sub', $payload->sub ?? null);
        } catch (\Throwable $e) {
            Log::warning('IamClient::VerifyIamToken - token invalid; clearing session', [
                'error' => $e->getMessage(),
                'session_id' => $request->session()->getId(),
            ]);

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
}
