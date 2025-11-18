<?php

namespace Juniyasyos\IamClient\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class LogoutController extends Controller
{
    /**
     * Handle user logout.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function __invoke(Request $request)
    {
        $userId = Auth::id();
        $sessionId = session()->getId();

        Log::info('SSO logout initiated', [
            'user_id' => $userId,
            'session_id' => $sessionId,
        ]);

        Auth::logout();

        request()->session()->invalidate();
        request()->session()->regenerateToken();

        Log::info('SSO logout completed', [
            'previous_user_id' => $userId,
            'old_session_id' => $sessionId,
            'new_session_id' => session()->getId(),
        ]);

        return redirect()->route('home')->with('message', 'You have been logged out successfully.');
    }
}
