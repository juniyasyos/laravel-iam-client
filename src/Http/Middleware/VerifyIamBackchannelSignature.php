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

        $appKey = $request->query('app_key') ?: $request->header('X-IAM-App-Key');
        $globalSecret = config('iam.sso_secret', config('sso.secret', env('SSO_SECRET', '')));
        $appSecret = null;

        if (! empty($appKey)) {
            try {
                $applicationClass = config('iam.application_model', \App\Domain\Iam\Models\Application::class);
                if (class_exists($applicationClass)) {
                    $application = $applicationClass::where('app_key', $appKey)->first();
                    if ($application && ! empty($application->secret)) {
                        $appSecret = $application->secret;
                    }
                }
            } catch (\Throwable $e) {
                // ignore; fallback to global secret
            }
        }

        $secret = $globalSecret ?: $appSecret;
        $secretSource = ! empty($globalSecret)
            ? 'config'
            : (! empty($appSecret) ? 'database' : 'none');

        // Verify against primary secret, then fallback to alternate one for compatibility.
        $validSignature = false;
        $expectedSignature = null;

        if (! empty($secret)) {
            $expectedSignature = hash_hmac('sha256', $body, $secret);
            $validSignature = hash_equals($expectedSignature, $signature);
        }

        if (! $validSignature && ! empty($globalSecret) && ! empty($appSecret)) {
            $alternateExpectedSignature = hash_hmac('sha256', $body, $appSecret);
            if (hash_equals($alternateExpectedSignature, $signature)) {
                $validSignature = true;
                $secretSource = 'database';
                $expectedSignature = $alternateExpectedSignature;
            }
        }

        if (empty($secret) || ! $validSignature) {
            \Illuminate\Support\Facades\Log::warning('iam.backchannel_signature_invalid', [
                'method' => 'hmac',
                'header_present' => ! empty($signature),
                'app_key' => $appKey,
                'secret_source' => $secretSource,
                'expected_signature' => $expectedSignature,
                'request_id' => $request->header('X-IAM-Request-Id'),
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'invalid signature'], 403);
        }

        \Illuminate\Support\Facades\Log::info('iam.backchannel_signature_valid', [
            'method' => 'hmac',
            'header' => $header,
            'signature_present' => ! empty($signature),
            'app_key' => $appKey,
            'secret_source' => $secretSource,
            'request_id' => $request->header('X-IAM-Request-Id'),
            'ip' => $request->ip(),
        ]);

        return $next($request);
    }
}
