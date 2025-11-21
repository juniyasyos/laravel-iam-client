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

            return redirect()->route($loginRoute)
                ->with('warning', 'Please login to continue.');
        }

        Log::debug('IAM auth middleware: User authenticated', [
            'user_id' => $guardInstance->id(),
            'path' => $request->path(),
            'session_id' => $sessionId,
            'guard' => $guardName,
        ]);

        return $next($request);
    }
}
