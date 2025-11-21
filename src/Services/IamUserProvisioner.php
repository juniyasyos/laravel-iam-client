<?php

namespace Juniyasyos\IamClient\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Juniyasyos\IamClient\Exceptions\IamAuthenticationException;
use Juniyasyos\IamClient\Support\IamConfig;

class IamUserProvisioner
{
    /**
     * Provision user from IAM token using HTTP verification.
     *
     * @param string $token Token from IAM
     * @return array{0: \Illuminate\Contracts\Auth\Authenticatable, 1: array}
     *
     * @throws IamAuthenticationException
     */
    public function provisionFromToken(string $token)
    {
        Log::info('SSO token provisioning started', [
            'token' => $token ? 'present' : 'missing',
            'session_id' => session()->getId(),
        ]);

        if (! $token) {
            throw new IamAuthenticationException('Missing token');
        }

        $verifyEndpoint = IamConfig::verifyEndpoint();

        try {
            $response = Http::timeout(10)->asJson()->post($verifyEndpoint, ['token' => $token]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('IAM server unavailable during token verification', [
                'token_preview' => substr($token, 0, 10) . '...',
                'endpoint' => $verifyEndpoint,
                'error' => $e->getMessage(),
            ]);

            throw new IamAuthenticationException(
                'Authentication server temporarily unavailable. Please try again.',
                ['endpoint' => $verifyEndpoint]
            );
        }

        if (! $response->ok()) {
            Log::warning('SSO verify failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new IamAuthenticationException('SSO token invalid/expired', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }

        $payload = $response->json() ?? [];

        $user = $this->syncUser($payload);
        $this->syncRoles($user, $payload);

        Log::info('User provisioned', [
            'user_id' => $user->getAuthIdentifier(),
            'email' => $user->email ?? null,
        ]);

        return [$user, $payload];
    }

    protected function syncUser(array $payload): Model
    {
        $fields = config('iam.user_fields', []);
        $identifierField = config('iam.identifier_field', 'email');

        $attributes = [];

        foreach ($fields as $column => $claim) {
            $value = data_get($payload, $claim);

            if ($value !== null) {
                $attributes[$column] = $value;
            }
        }

        if (! array_key_exists($identifierField, $attributes)) {
            throw new IamAuthenticationException(
                sprintf('Missing identifier field [%s] from IAM payload.', $identifierField)
            );
        }

        if (! array_key_exists('password', $attributes)) {
            $attributes['password'] = Str::password(32);
        }

        $userModel = config('iam.user_model', 'App\\Models\\User');

        return $userModel::query()->updateOrCreate(
            [$identifierField => $attributes[$identifierField]],
            $attributes,
        );
    }

    protected function syncRoles(Model $user, array $payload): void
    {
        if (! config('iam.sync_roles', true)) {
            return;
        }

        if (! method_exists($user, 'syncRoles')) {
            Log::debug('IAM role sync skipped because syncRoles() is missing on the user model.');

            return;
        }

        $roles = array_filter(array_map(function ($role) {
            if (is_array($role)) {
                return $role['slug'] ?? $role['name'] ?? null;
            }

            return is_string($role) ? $role : null;
        }, $payload['roles'] ?? []));

        if (empty($roles)) {
            return;
        }

        $user->syncRoles($roles);
    }
}
