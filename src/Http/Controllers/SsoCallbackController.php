<?php

namespace Juniyasyos\IamClient\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Juniyasyos\IamClient\Exceptions\IamAuthenticationException;
use Juniyasyos\IamClient\Services\IamClientManager;
use Juniyasyos\IamClient\Support\IamConfig;

class SsoCallbackController extends Controller
{
    public function __construct(private readonly IamClientManager $manager) {}

    /**
     * Handle SSO callback from IAM server.
     *
     * Returns either a RedirectResponse (on success / intended redirect)
     * or a Response when rendering the callback view with an error.
     */
    public function __invoke(Request $request, string $guard = 'web'): Response|RedirectResponse
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

            // Prevent redirect loop: do NOT redirect back to SSO login on provisioning/authorization errors.
            // Instead render the callback handler with a server-side error message so the client UI can show a popup.
            $message = $exception->getMessage();

            return response()->view('iam-client::callback-handler', [
                'serverError' => $message,
                'serverErrorContext' => $exception->context ?? null,
            ], 403);
        }

        $redirectTo = IamConfig::guardRedirect($guard);

        return redirect()->intended($redirectTo);
    }
}
