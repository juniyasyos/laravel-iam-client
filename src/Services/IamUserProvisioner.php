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

        // Normalize payload fields coming from IAM /api/sso/verify.
        // Verify endpoint returns token claims under token_info, but other flows may include them at top level.
        $tokenInfo = data_get($payload, 'token_info', []);

        $payload['sub'] = $payload['sub'] ?? data_get($tokenInfo, 'sub');
        $payload['app'] = $payload['app'] ?? data_get($tokenInfo, 'app');
        $payload['roles'] = $payload['roles'] ?? data_get($tokenInfo, 'roles', []);
        $payload['perms'] = $payload['perms'] ?? data_get($tokenInfo, 'perms', []);

        if (! isset($payload['sub'])) {
            Log::warning('IAM verify payload missing sub/app in both root and token_info', [
                'payload' => $payload,
                'token_info' => $tokenInfo,
            ]);
        }

        $unitKerjaItems = $this->resolveUnitKerjaItems($payload);

        if (config('iam.require_unit_kerja', false) && count($unitKerjaItems) === 0) {
            throw new IamAuthenticationException(
                sprintf('Unable to provision SSO user: missing required unit kerja claim [%s].', config('iam.unit_kerja_field', 'unit_kerja'))
            );
        }

        $user = $this->syncUser($payload);
        $this->syncUnitKerja($user, $unitKerjaItems);
        $this->syncRoles($user, $payload);

        Log::info('User provisioned', [
            'user_id' => $user->getAuthIdentifier(),
            'email' => $user->email ?? null,
            'unit_kerja' => $this->summarizeUnitKerjaItems($unitKerjaItems),
        ]);

        return [$user, $payload];
    }

    protected function resolveUnitKerjaItems(array $payload): array
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
            $values = array_filter(array_map(function ($item) {
                $item = trim($item);

                return $item === '' ? null : ['unit_name' => $item];
            }, explode(',', $raw)));

            return array_values($values);
        }

        if (is_array($raw)) {
            if (array_is_list($raw)) {
                $items = $raw;
            } else {
                $items = [$raw];
            }

            $normalized = [];

            foreach ($items as $item) {
                $normalizedItem = $this->normalizeUnitKerjaItem($item);

                if ($normalizedItem !== null) {
                    $normalized[] = $normalizedItem;
                }
            }

            return $normalized;
        }

        $rawValue = trim((string) $raw);

        return $rawValue === '' ? [] : [['unit_name' => $rawValue]];
    }

    protected function normalizeUnitKerjaItem(mixed $item): ?array
    {
        if (is_string($item)) {
            $item = trim($item);

            return $item === '' ? null : ['unit_name' => $item];
        }

        if (is_numeric($item)) {
            return ['id' => (int) $item];
        }

        if (! is_array($item)) {
            return null;
        }

        $id = data_get($item, 'id');
        $slug = trim((string) data_get($item, 'slug', ''));
        $unitName = trim((string) data_get($item, 'unit_name', data_get($item, 'name', '')));
        $description = data_get($item, 'description');

        $normalized = [];

        if (is_numeric($id)) {
            $normalized['id'] = (int) $id;
        }

        if ($slug !== '') {
            $normalized['slug'] = $slug;
        }

        if ($unitName !== '') {
            $normalized['unit_name'] = $unitName;
        }

        if ($description !== null || array_key_exists('description', $item)) {
            $normalized['description'] = $description;
        }

        return empty($normalized) ? null : $normalized;
    }

    protected function syncUnitKerja(Model $user, array $unitKerjaItems): void
    {
        if (! config('iam.sync_unit_kerja', true)) {
            return;
        }

        if (empty($unitKerjaItems)) {
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

        foreach ($unitKerjaItems as $unitKerjaItem) {
            $unit = $this->resolveUnitKerjaRecord($unitKerjaModel, $unitKerjaItem, 'Synced from IAM provisioning');

            if ($unit) {
                $unitIds[] = $unit->getKey();
            }
        }

        if (! empty($unitIds)) {
            $user->unitKerjas()->sync(array_values(array_unique($unitIds)));
            Log::info('User unit_kerja synced from IAM', ['user_id' => $user->getAuthIdentifier(), 'unit_kerja' => $this->summarizeUnitKerjaItems($unitKerjaItems)]);
        }
    }

    protected function resolveUnitKerjaRecord(string $unitKerjaModel, array $unitKerjaData, ?string $defaultDescription = null): ?object
    {
        $id = data_get($unitKerjaData, 'id');
        $slug = trim((string) data_get($unitKerjaData, 'slug', ''));
        $unitName = trim((string) data_get($unitKerjaData, 'unit_name', ''));
        $description = array_key_exists('description', $unitKerjaData)
            ? data_get($unitKerjaData, 'description')
            : $defaultDescription;

        $unit = null;

        if (is_numeric($id)) {
            $unit = $unitKerjaModel::withTrashed()->find($id);
        }

        if (! $unit && $slug !== '') {
            $unit = $unitKerjaModel::withTrashed()->firstOrNew(['slug' => $slug]);
        }

        if (! $unit && $unitName !== '') {
            $unit = $unitKerjaModel::withTrashed()->firstOrNew(['unit_name' => $unitName]);
        }

        if (! $unit) {
            return null;
        }

        $wasTrashed = method_exists($unit, 'trashed') && $unit->trashed();
        $shouldSave = ! $unit->exists || $wasTrashed;

        $attributes = [];

        if ($unitName !== '') {
            $attributes['unit_name'] = $unitName;
        } elseif (! $unit->exists && $slug !== '') {
            $attributes['unit_name'] = Str::of($slug)->replace(['-', '_'], ' ')->title()->toString();
        }

        if ($slug !== '') {
            $attributes['slug'] = $slug;
        }

        if ($description !== null || array_key_exists('description', $unitKerjaData)) {
            $attributes['description'] = $description;
        }

        if (! empty($attributes)) {
            $unit->fill($attributes);
            $shouldSave = true;
        }

        if ($wasTrashed) {
            $unit->restore();
            $shouldSave = true;
        }

        if ($shouldSave) {
            $unit->save();
        }

        return $unit;
    }

    protected function summarizeUnitKerjaItems(array $unitKerjaItems): array
    {
        return array_values(array_filter(array_map(function (array $item) {
            return $item['unit_name'] ?? $item['slug'] ?? ($item['id'] ?? null);
        }, $unitKerjaItems)));
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
                // Always use slug if available, NEVER use display name directly
                $slug = $role['slug'] ?? null;
                if (!$slug && isset($role['name'])) {
                    // Convert display name to slug format (lowercase, replace spaces/hyphens with underscore)
                    $slug = strtolower(preg_replace('/[\s\-]+/', '_', $role['name']));
                }
                return $slug;
            }

            return is_string($role) ? $role : null;
        }, is_array($rolesPayload) ? $rolesPayload : []));

        if (empty($roles)) {
            return;
        }

        $user->syncRoles($roles);
    }
}
