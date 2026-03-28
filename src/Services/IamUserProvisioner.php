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

        $unitKerjaValues = $this->resolveUnitKerjaValues($payload);

        if (config('iam.require_unit_kerja', false) && count($unitKerjaValues) === 0) {
            throw new IamAuthenticationException(
                sprintf('Unable to provision SSO user: missing required unit kerja claim [%s].', config('iam.unit_kerja_field', 'unit_kerja'))
            );
        }

        $user = $this->syncUser($payload);
        $this->syncUnitKerja($user, $unitKerjaValues);
        $this->syncRoles($user, $payload);

        Log::info('User provisioned', [
            'user_id' => $user->getAuthIdentifier(),
            'email' => $user->email ?? null,
            'unit_kerja' => $unitKerjaValues,
        ]);

        return [$user, $payload];
    }

    protected function resolveUnitKerjaValues(array $payload): array
    {
        $field = config('iam.unit_kerja_field', 'unit_kerja');

        $raw = data_get($payload, $field, data_get($payload, "token_info.{$field}"));

        if ($raw === null) {
            return [];
        }

        if (is_string($raw)) {
            if (trim($raw) === '') {
                return [];
            }

            // support comma-separated string, e.g. "unit1,unit2"
            $values = array_filter(array_map('trim', explode(',', $raw)), fn($item) => $item !== '');

            return array_values(array_unique($values));
        }

        if (is_array($raw)) {
            $values = array_filter(array_map(function ($item) {
                if (is_string($item)) {
                    return trim($item);
                }

                return $item;
            }, $raw), fn($item) => $item !== null && $item !== '');

            return array_values(array_unique($values));
        }

        return [(string) $raw];
    }

    protected function syncUnitKerja(Model $user, array $unitKerjaValues): void
    {
        if (! config('iam.sync_unit_kerja', true)) {
            return;
        }

        if (empty($unitKerjaValues)) {
            return;
        }

        if (! method_exists($user, 'unitKerjas')) {
            Log::warning('IAM unit_kerja sync skipped because unitKerjas() relation is missing on user model.');
            return;
        }

        $unitKerjaModel = config('iam.unit_kerja_model', \App\Models\UnitKerja::class);

        if (! class_exists($unitKerjaModel)) {
            Log::warning('IAM unit_kerja sync skipped because unit_kerja_model class does not exist.', ['unit_kerja_model' => $unitKerjaModel]);
            return;
        }

        $unitIds = [];

        foreach ($unitKerjaValues as $unitValue) {
            if (is_numeric($unitValue) && $unitKerjaModel::whereKey($unitValue)->exists()) {
                $unit = $unitKerjaModel::find($unitValue);
            } else {
                $unit = $unitKerjaModel::firstOrCreate(['unit_name' => (string) $unitValue], ['description' => 'Synced from IAM provisioning']);
            }

            if ($unit) {
                $unitIds[] = $unit->getKey();
            }
        }

        if (! empty($unitIds)) {
            $user->unitKerjas()->sync(array_values(array_unique($unitIds)));
            Log::info('User unit_kerja synced from IAM', ['user_id' => $user->getAuthIdentifier(), 'unit_kerja' => $unitKerjaValues]);
        }
    }

    protected function syncUser(array $payload): Model
    {
        $fields = config('iam.user_fields', []);
        $identifierField = config('iam.identifier_field', 'email');

        $attributes = [];

        foreach ($fields as $column => $claim) {
            $value = data_get($payload, $claim);

            // Fallback untuk payload yang berisi data di token_info (legacy/docs)
            if ($value === null) {
                $value = data_get($payload, "token_info.{$claim}");
            }

            if ($value !== null) {
                $attributes[$column] = $value;
            }
        }

        // Pastikan nip selalu tersedia jika dikirim server SSO
        if (! array_key_exists('nip', $attributes)) {
            $nip = data_get($payload, 'nip', data_get($payload, 'token_info.nip'));
            if ($nip !== null) {
                $attributes['nip'] = $nip;
            }
        }

        $identifierValue = $attributes[$identifierField] ?? null;

        // Jika identifier yang dikonfigurasi tidak tersedia, fallback ke NIP jika ada
        if (empty($identifierValue) && ! empty($attributes['nip'])) {
            $identifierField = 'nip';
            $identifierValue = $attributes['nip'];
        }

        if (empty($identifierValue)) {
            throw new IamAuthenticationException(
                sprintf('Unable to provision SSO user: missing identifier field [%s] and no NIP available in payload.', config('iam.identifier_field', 'email'))
            );
        }

        if (! array_key_exists('password', $attributes)) {
            $attributes['password'] = Str::password(32);
        }

        $userModel = config('iam.user_model', 'App\\Models\\User');

        $user = $userModel::query()->updateOrCreate(
            [$identifierField => $identifierValue],
            $attributes
        );

        $action = $user->wasRecentlyCreated ? 'created' : 'updated';
        Log::info('User provisioning ' . $action, [
            'user_id' => $user->getAuthIdentifier(),
            'identifier_field' => $identifierField,
            'identifier_value' => $identifierValue,
            'email' => $user->email ?? null,
            'nip' => $user->nip ?? null,
            'unit_kerja' => $user->unitKerjas()->pluck('unit_name')->toArray(),
        ]);

        return $user;
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

        $rolesClaim = config('iam.roles_field', 'roles');
        $rolesPayload = data_get($payload, $rolesClaim, $payload['roles'] ?? []);

        $roles = array_filter(array_map(function ($role) {
            if (is_array($role)) {
                return $role['slug'] ?? $role['name'] ?? null;
            }

            return is_string($role) ? $role : null;
        }, is_array($rolesPayload) ? $rolesPayload : []));

        if (empty($roles)) {
            return;
        }

        $user->syncRoles($roles);
    }
}
