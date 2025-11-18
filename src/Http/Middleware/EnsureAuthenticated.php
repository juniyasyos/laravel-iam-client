<?php

namespace Juniyasyos\IamClient\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EnsureAuthenticated
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Force session start if not started
        if (!session()->isStarted()) {
            session()->start();
        }

        $sessionId = session()->getId();

        if (!Auth::check()) {
            Log::info('IAM auth middleware: User not authenticated', [
                'path' => $request->path(),
                'session_id' => $sessionId,
                'session_started' => session()->isStarted(),
            ]);

            // Store intended URL
            session(['url.intended' => $request->fullUrl()]);

            // Get login route from config
            $loginRoute = config('iam.login_route_name', 'login');

            return redirect()->route($loginRoute)
                ->with('warning', 'Please login to continue.');
        }

        Log::debug('IAM auth middleware: User authenticated', [
            'user_id' => Auth::id(),
            'path' => $request->path(),
            'session_id' => $sessionId,
        ]);

        return $next($request);
    }
}
