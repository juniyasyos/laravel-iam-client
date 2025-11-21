<?php

namespace Juniyasyos\IamClient\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Juniyasyos\IamClient\Support\IamConfig;

class LogoutController extends Controller
{
    /**
     * Handle user logout.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function __invoke(Request $request, string $guard = 'web')
    {
        $guardName = IamConfig::guardName($guard);
        $guardInstance = Auth::guard($guardName);

        $userId = $guardInstance->id();
        $sessionId = session()->getId();

        Log::info('SSO logout initiated', [
            'user_id' => $userId,
            'session_id' => $sessionId,
            'guard' => $guardName,
        ]);

        $guardInstance->logout();

        request()->session()->invalidate();
        request()->session()->regenerateToken();
        request()->session()->forget('iam');

        Log::info('SSO logout completed', [
            'previous_user_id' => $userId,
            'old_session_id' => $sessionId,
            'new_session_id' => session()->getId(),
            'guard' => $guardName,
        ]);

        $redirectRouteName = IamConfig::logoutRedirectRoute($guard);

        if ($redirectRouteName && Route::has($redirectRouteName)) {
            return redirect()->route($redirectRouteName)->with('message', 'You have been logged out successfully.');
        }

        return redirect(IamConfig::guardRedirect($guard))->with('message', 'You have been logged out successfully.');
    }
}
