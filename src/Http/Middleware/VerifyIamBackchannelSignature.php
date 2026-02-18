<?php

namespace Juniyasyos\IamClient\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VerifyIamBackchannelSignature
{
    /**
     * Verify HMAC SHA256 signature of back‑channel requests.
     * Expects shared secret at config('sso.secret') or env('SSO_SECRET').
     */
    public function handle(Request $request, Closure $next)
    {
        $header = config('sso.backchannel.signature_header', 'IAM-Signature');
        $signature = (string) $request->header($header, '');
        $body = $request->getContent() ?: '';
        $secret = config('sso.secret', env('SSO_SECRET', ''));

        if (empty($secret) || ! hash_equals(hash_hmac('sha256', $body, $secret), $signature)) {
            return response()->json(['message' => 'invalid signature'], 403);
        }

        return $next($request);
    }
}
