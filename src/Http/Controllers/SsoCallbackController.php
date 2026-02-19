<?php

namespace Juniyasyos\IamClient\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Juniyasyos\IamClient\Exceptions\IamAuthenticationException;
use Juniyasyos\IamClient\Services\IamClientManager;
use Juniyasyos\IamClient\Support\IamConfig;

class SsoCallbackController extends Controller
{
    public function __construct(private readonly IamClientManager $manager)
    {
    }

    /**
     * Handle SSO callback from IAM server.
     */
    public function __invoke(Request $request, string $guard = 'web'): RedirectResponse
    {
        $token = $request->input('token') ?? $request->input('access_token');

        Log::info('SSO callback received', [
            'token' => $token ? 'present' : 'missing',
            'session_id' => session()->getId(),
            'guard' => $guard,
        ]);

        if (! $token) {
            abort(400, 'Missing token');
        }

        try {
            $this->manager->loginWithToken($token, $guard);
        } catch (IamAuthenticationException $exception) {
            Log::warning('IAM authentication failed', [
                'message' => $exception->getMessage(),
                'context' => $exception->context,
                'guard' => $guard,
            ]);

            $loginRoute = IamConfig::loginRouteName($guard);

            if (\Illuminate\Support\Facades\Route::has($loginRoute)) {
                return redirect()->route($loginRoute)->withErrors([
                    'sso' => $exception->getMessage(),
                ]);
            }

            // Fallback to configured login path when route name is not present
            $loginPath = config('iam.login_route', '/sso/login');

            return redirect()->to($loginPath)->withErrors([
                'sso' => $exception->getMessage(),
            ]);
        }

        $redirectTo = IamConfig::guardRedirect($guard);

        return redirect()->intended($redirectTo);
    }
}
