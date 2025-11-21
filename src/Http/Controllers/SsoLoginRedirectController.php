<?php

namespace Juniyasyos\IamClient\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Juniyasyos\IamClient\Support\IamConfig;

class SsoLoginRedirectController extends Controller
{
    /**
     * Redirect user to IAM login page.
     */
    public function __invoke(Request $request, string $guard = 'web')
    {
        $app = IamConfig::appKey();
        $iam = IamConfig::baseUrl();

        $callbackRoute = IamConfig::callbackRouteName($guard);
        $callback = urlencode(route($callbackRoute));

        return redirect()->away("{$iam}/sso/redirect?app={$app}&callback={$callback}");
    }
}
