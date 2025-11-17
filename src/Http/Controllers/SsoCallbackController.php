<?php

namespace Juniyasyos\IamClient\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Juniyasyos\IamClient\Services\IamUserProvisioner;

class SsoCallbackController extends Controller
{
    /**
     * Handle SSO callback from IAM server.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Juniyasyos\IamClient\Services\IamUserProvisioner $provisioner
     * @return \Illuminate\Http\RedirectResponse
     */
    public function __invoke(Request $request, IamUserProvisioner $provisioner)
    {
        // Get access token from request (can be from query string or POST body)
        $accessToken = $request->input('access_token');

        if (!$accessToken) {
            Log::warning('IAM callback received without access token');
            abort(400, 'Access token is required.');
        }

        Log::info('IAM callback received', [
            'has_token' => !empty($accessToken),
        ]);

        try {
            // Provision user from token (JIT provisioning + login)
            $user = $provisioner->provisionFromToken($accessToken);

            // Get redirect URL (intended or default)
            $redirect = session()->pull(
                'intended',
                config('iam.default_redirect_after_login', '/')
            );

            Log::info('IAM login successful, redirecting', [
                'user_id' => $user->id,
                'redirect' => $redirect,
            ]);

            return redirect()->to($redirect);
        } catch (\Exception $e) {
            Log::error('IAM callback failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect('/')->withErrors([
                'sso' => 'SSO authentication failed: ' . $e->getMessage(),
            ]);
        }
    }
}
