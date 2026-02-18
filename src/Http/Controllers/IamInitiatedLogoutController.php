<?php

namespace Juniyasyos\IamClient\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Juniyasyos\IamClient\Support\IamConfig;

class IamInitiatedLogoutController extends Controller
{
    /**
     * Handle OP‑initiated (front‑channel) logout called by IAM.
     * Public endpoint — does NOT require authentication.
     */
    public function __invoke(Request $request, string $guard = 'web')
    {
        Log::info('OP‑initiated logout received', [
            'session_id' => session()->getId(),
            'guard' => $guard,
            'auth_checked' => auth()->check(),
            'auth_user_id' => auth()->id(),
            'request_id' => $request->query('request_id') ?? $request->header('X-IAM-Request-Id'),
        ]);

        // Only remove IAM-related session keys (non‑destructive for app auth)
        session()->forget([
            'iam_access_token',
            'iam_refresh_token',
            'iam_expires_at',
            'iam_user',
            'iam',
        ]);

        // Regenerate CSRF token to reduce fixation risk
        $request->session()->regenerateToken();

        // Allow OP to pass `post_logout_redirect` so IAM can continue the
        // logout chain. Only accept redirects back to IAM (`IamConfig::baseUrl()`).
        $post = $request->query('post_logout_redirect');

        if ($post && str_starts_with((string) $post, IamConfig::baseUrl())) {
            return redirect($post);
        }

        $redirect = IamConfig::guardRedirect($guard);

        return redirect($redirect);
    }
}
