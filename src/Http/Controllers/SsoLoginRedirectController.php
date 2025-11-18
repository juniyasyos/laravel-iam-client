<?php

namespace Juniyasyos\IamClient\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SsoLoginRedirectController extends Controller
{
    /**
     * Redirect user to IAM login page.
     */
    public function __invoke(Request $request)
    {
        $app = config('services.iam.app', 'client-example');
        $iam = rtrim((string) config('services.iam.host'), '/');

        $callback = urlencode(route('iam.sso.callback'));

        return redirect()->away("{$iam}/sso/redirect?app={$app}&callback={$callback}");
    }
}
