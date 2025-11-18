<?php

namespace Juniyasyos\IamClient\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SsoCallbackController extends Controller
{
    /**
     * Handle SSO callback from IAM server.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $token = request('token');

        Log::info('SSO callback received', [
            'token' => $token ? 'present' : 'missing',
            'session_id' => session()->getId(),
        ]);

        abort_if(! $token, 400, 'Missing token');

        $verifyEndpoint = config('services.iam.verify');
        abort_if(! $verifyEndpoint, 500, 'IAM verify endpoint is not configured');

        try {
            $response = Http::timeout(10)->asJson()->post($verifyEndpoint, ['token' => $token]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('IAM server unavailable during token verification', [
                'token_preview' => substr($token, 0, 10) . '...',
                'endpoint' => $verifyEndpoint,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('login')->withErrors([
                'sso' => 'Authentication server temporarily unavailable. Please try again.',
            ]);
        }

        if (! $response->ok()) {
            Log::warning('SSO verify failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return redirect()->route('login')->withErrors([
                'sso' => 'SSO token invalid/expired',
            ]);
        }

        $payload = $response->json();
        abort_unless(isset($payload['email']) && is_string($payload['email']), 422, 'Missing user email');

        $userModel = config('iam.user_model', 'App\\Models\\User');
        $user = $userModel::query()->updateOrCreate(
            ['email' => $payload['email']],
            [
                'name' => $payload['name'] ?? $payload['email'],
                'password' => Str::password(32),
            ],
        );

        // Use CustomAuth facade to login without session regeneration
        $customAuthClass = 'App\\Facades\\CustomAuth';
        if (class_exists($customAuthClass)) {
            $success = $customAuthClass::loginWithoutRegeneration($user, true);
            
            if (!$success) {
                Log::warning('CustomAuth failed, using Auth::loginUsingId fallback');
                Auth::loginUsingId($user->id, true);
            }
        } else {
            // Fallback to standard login
            Auth::loginUsingId($user->id, true);
        }

        session([
            'iam' => [
                'sub' => $payload['sub'] ?? null,
                'app' => $payload['app'] ?? null,
                'roles' => $payload['roles'] ?? [],
                'perms' => $payload['perms'] ?? [],
            ],
        ]);

        Log::info('SSO callback OK', [
            'user' => $user->id,
            'email' => $user->email,
            'sid' => session()->getId(),
            'auth_check' => Auth::check(),
        ]);

        return redirect()->route('home');
    }
}
