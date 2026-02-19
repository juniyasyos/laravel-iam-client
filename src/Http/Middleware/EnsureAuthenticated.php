<?php

namespace Juniyasyos\IamClient\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Juniyasyos\IamClient\Support\IamConfig;

class EnsureAuthenticated
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string $guard = 'web')
    {
        // Force session start if not started
        if (! session()->isStarted()) {
            session()->start();
        }

        $sessionId = session()->getId();
        $guardName = IamConfig::guardName($guard);
        $guardInstance = Auth::guard($guardName);

        // --- Primary check: require a valid IAM access token in session ----------
        $accessToken = session('iam.access_token');

        if (empty($accessToken)) {
            Log::info('IAM auth middleware: missing access_token in session; forcing logout', [
                'path' => $request->path(),
                'session_id' => $sessionId,
                'guard' => $guardName,
            ]);

            // Clear local auth/session state and redirect to SSO login
            if ($guardInstance->check()) {
                $guardInstance->logout();
            }

            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $request->session()->forget('iam');

            // Preserve intended URL so user returns after SSO
            session(['url.intended' => $request->fullUrl()]);

            $loginRoute = IamConfig::loginRouteName($guard);

            if (\Illuminate\Support\Facades\Route::has($loginRoute)) {
                return redirect()->route($loginRoute)->with('warning', 'Please login to continue.');
            }

            return redirect()->to(config('iam.login_route', '/sso/login'))->with('warning', 'Please login to continue.');
        }

        // --- Decode & validate token locally (signature + exp + iss/aud when configured) ---
        try {
            $payload = \Juniyasyos\IamClient\Support\TokenValidator::decode($accessToken);
        } catch (\Throwable $e) {
            Log::warning('IAM auth middleware: access token invalid — clearing session', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
            ]);

            if ($guardInstance->check()) {
                $guardInstance->logout();
            }

            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $request->session()->forget('iam');

            $loginRoute = IamConfig::loginRouteName($guard);

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['message' => 'Session invalid, please login again.'], 401);
            }

            if (\Illuminate\Support\Facades\Route::has($loginRoute)) {
                return redirect()->route($loginRoute)->with('warning', 'Session invalid, please login again.');
            }

            return redirect()->to(config('iam.login_route', '/sso/login'))->with('warning', 'Session invalid, please login again.');
        }

        // Ensure token contains subject
        $tokenSub = $payload->sub ?? null;
        if (! $tokenSub) {
            Log::warning('IAM auth middleware: token missing sub claim — clearing session', ['session_id' => $sessionId]);

            if ($guardInstance->check()) {
                $guardInstance->logout();
            }

            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $request->session()->forget('iam');

            $loginRoute = IamConfig::loginRouteName($guard);
            if (\Illuminate\Support\Facades\Route::has($loginRoute)) {
                return redirect()->route($loginRoute)->with('warning', 'Invalid session.');
            }

            return redirect()->to(config('iam.login_route', '/sso/login'))->with('warning', 'Invalid session.');
        }

        // Prevent session reuse across different SSO subjects
        $sessionSub = session('iam.sub');
        if (! $sessionSub || ((string) $sessionSub !== (string) $tokenSub)) {
            Log::info('IAM auth middleware: token subject mismatch with session; clearing session', [
                'token_sub' => $tokenSub,
                'session_sub' => $sessionSub,
                'session_id' => $sessionId,
            ]);

            if ($guardInstance->check()) {
                $guardInstance->logout();
            }

            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $request->session()->forget('iam');

            $loginRoute = IamConfig::loginRouteName($guard);
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['message' => 'Session mismatch, please login again.'], 401);
            }

            if (\Illuminate\Support\Facades\Route::has($loginRoute)) {
                return redirect()->route($loginRoute)->with('warning', 'Session mismatch, please login again.');
            }

            return redirect()->to(config('iam.login_route', '/sso/login'))->with('warning', 'Session mismatch, please login again.');
        }

        // Optional: enforce roles for active sessions (existing behaviour)
        if (config('iam.require_roles', false)) {
            $sessionRoles = session('iam.roles', []);

            if (empty($sessionRoles)) {
                Log::warning('IAM auth middleware: rejecting session because no roles present', [
                    'user_id' => $guardInstance->id(),
                    'session_id' => $sessionId,
                    'path' => $request->path(),
                ]);

                if ($guardInstance->check()) {
                    $guardInstance->logout();
                }

                $request->session()->invalidate();
                $request->session()->regenerateToken();
                $request->session()->forget('iam');

                $loginRoute = IamConfig::loginRouteName($guard);

                if (\Illuminate\Support\Facades\Route::has($loginRoute)) {
                    return redirect()->route($loginRoute)->with('warning', 'Access denied: no roles assigned in SSO token.');
                }

                return redirect()->to(config('iam.login_route', '/sso/login'))->with('warning', 'Access denied: no roles assigned in SSO token.');
            }
        }

        // Keep session payload synced with verified token
        session(['iam.payload' => (array) $payload, 'iam.sub' => $tokenSub]);

        return $next($request);
    }
}
