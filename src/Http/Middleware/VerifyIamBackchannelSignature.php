<?php

namespace Juniyasyos\IamClient\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VerifyIamBackchannelSignature
{
    /**
     * Verify back‑channel authorization using either a signed JWT or an HMAC.
     *
     * The behaviour is controlled by `iam.backchannel_method` configuration
     * value on the client side (`jwt` or `hmac`).  This middleware is still
     * aliased as `iam.backchannel.verify` for compatibility.
     */
    public function handle(Request $request, Closure $next)
    {
        // allow disabling verification completely via config
        if (! config('iam.backchannel_verify', true)) {
            return $next($request);
        }

        $method = config('iam.backchannel_method', 'hmac');

        if ($method === 'jwt') {
            // expect a bearer token
            $token = $request->bearerToken();

            if (empty($token)) {
                \Illuminate\Support\Facades\Log::warning('iam.backchannel_signature_invalid', [
                    'method' => 'jwt',
                    'token_present' => false,
                    'request_id' => $request->header('X-IAM-Request-Id'),
                    'ip' => $request->ip(),
                ]);

                return response()->json(['message' => 'invalid token'], 403);
            }

            try {
                $decoded = \Juniyasyos\IamClient\Support\TokenValidator::decode($token);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('iam.backchannel_signature_invalid', [
                    'method' => 'jwt',
                    'token_present' => true,
                    'error' => $e->getMessage(),
                    'request_id' => $request->header('X-IAM-Request-Id'),
                    'ip' => $request->ip(),
                ]);

                return response()->json(['message' => 'invalid token'], 403);
            }

            \Illuminate\Support\Facades\Log::info('iam.backchannel_signature_valid', [
                'method' => 'jwt',
                'token_present' => true,
                'request_id' => $request->header('X-IAM-Request-Id'),
                'ip' => $request->ip(),
            ]);

            return $next($request);
        }

        // legacy HMAC verification
        $header = config('sso.backchannel.signature_header', 'IAM-Signature');
        $signature = (string) $request->header($header, '');
        $body = $request->getContent() ?: '';
        $secret = config('sso.secret', env('SSO_SECRET', ''));

        // Log verification attempt (do not log raw body or signature)
        if (empty($secret) || ! hash_equals(hash_hmac('sha256', $body, $secret), $signature)) {
            \Illuminate\Support\Facades\Log::warning('iam.backchannel_signature_invalid', [
                'method' => 'hmac',
                'header_present' => ! empty($signature),
                'request_id' => $request->header('X-IAM-Request-Id'),
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'invalid signature'], 403);
        }

        \Illuminate\Support\Facades\Log::info('iam.backchannel_signature_valid', [
            'method' => 'hmac',
            'header' => $header,
            'signature_present' => ! empty($signature),
            'request_id' => $request->header('X-IAM-Request-Id'),
            'ip' => $request->ip(),
        ]);

        return $next($request);
    }
}
