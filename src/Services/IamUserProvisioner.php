<?php

namespace Juniyasyos\IamClient\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class IamUserProvisioner
{
    /**
     * Provision user from IAM token using HTTP verification.
     *
     * @param string $token Token from IAM
     * @return \Illuminate\Database\Eloquent\Model
     * @throws \Exception
     */
    public function provisionFromToken(string $token)
    {
        Log::info('SSO token provisioning started', [
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

            throw new \Exception('Authentication server temporarily unavailable. Please try again.');
        }

        if (! $response->ok()) {
            Log::warning('SSO verify failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \Exception('SSO token invalid/expired');
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

        Log::info('User provisioned', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        // Store IAM session data
        session([
            'iam' => [
                'sub' => $payload['sub'] ?? null,
                'app' => $payload['app'] ?? null,
                'roles' => $payload['roles'] ?? [],
                'perms' => $payload['perms'] ?? [],
            ],
        ]);

        return $user;
    }
}
