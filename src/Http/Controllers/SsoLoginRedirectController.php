<?php

namespace Juniyasyos\IamClient\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class SsoLoginRedirectController extends Controller
{
    /**
     * Redirect user to IAM login page.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function __invoke(Request $request)
    {
        $iamBase = rtrim(config('iam.base_url'), '/');
        $callbackUrl = url(config('iam.callback_route', '/sso/callback'));

        Log::info('Redirecting to IAM login', [
            'callback_url' => $callbackUrl,
        ]);

        // Store intended URL for redirect after login
        if ($request->has('intended')) {
            session(['intended' => $request->input('intended')]);
        }

        // Build redirect URL to IAM
        $redirectUrl = "{$iamBase}/oauth/authorize?" . http_build_query([
            'redirect_uri' => $callbackUrl,
            'response_type' => 'token',
            'app_key' => config('iam.app_key'),
        ]);

        return redirect()->away($redirectUrl);
    }
}
