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
        if (!session()->isStarted()) {
            session()->start();
        }

        $sessionId = session()->getId();
        $guardName = IamConfig::guardName($guard);
        $guardInstance = Auth::guard($guardName);

        if (! $guardInstance->check()) {
            Log::info('IAM auth middleware: User not authenticated', [
                'path' => $request->path(),
                'session_id' => $sessionId,
                'session_started' => session()->isStarted(),
                'guard' => $guardName,
            ]);

            // Store intended URL
            session(['url.intended' => $request->fullUrl()]);

            // Get login route from config
            $loginRoute = IamConfig::loginRouteName($guard);

            if (\Illuminate\Support\Facades\Route::has($loginRoute)) {
                return redirect()->route($loginRoute)->with('warning', 'Please login to continue.');
            }

            return redirect()->to(config('iam.login_route', '/sso/login'))->with('warning', 'Please login to continue.');
        }

        Log::debug('IAM auth middleware: User authenticated', [
            'user_id' => $guardInstance->id(),
            'path' => $request->path(),
            'session_id' => $sessionId,
            'guard' => $guardName,
        ]);

        // Optional: verify stored IAM access token with IAM on every request.
        if (config('iam.verify_each_request', true)) {
            $accessToken = session('iam.access_token');

            if (! empty($accessToken)) {
                try {
                    $verifyEndpoint = IamConfig::verifyEndpoint();
                    $resp = \Illuminate\Support\Facades\Http::timeout(3)->post($verifyEndpoint, ['token' => $accessToken]);

                    if (! $resp->ok()) {
                        Log::warning('IAM middleware: token introspection failed — clearing session', [
                            'status' => $resp->status(),
                            'session_id' => $sessionId,
                        ]);

                        Auth::guard($guardName)->logout();
                        $request->session()->invalidate();
                        $request->session()->regenerateToken();
                        $request->session()->forget('iam');

                        $loginRoute = IamConfig::loginRouteName($guard);

                        if (\Illuminate\Support\Facades\Route::has($loginRoute)) {
                            return redirect()->route($loginRoute)->with('warning', 'Session expired, please login again.');
                        }

                        return redirect()->to(config('iam.login_route', '/sso/login'))->with('warning', 'Session expired, please login again.');
                    }
                } catch (\Exception $e) {
                    Log::error('IAM middleware: token introspection request failed', [
                        'error' => $e->getMessage(),
                    ]);

                    Auth::guard($guardName)->logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                    $request->session()->forget('iam');

                    $loginRoute = IamConfig::loginRouteName($guard);

                    if (\Illuminate\Support\Facades\Route::has($loginRoute)) {
                        return redirect()->route($loginRoute)->with('warning', 'Authentication verification failed.');
                    }

                    return redirect()->to(config('iam.login_route', '/sso/login'))->with('warning', 'Authentication verification failed.');
                }
            }
        }

        return $next($request);
    }
}
