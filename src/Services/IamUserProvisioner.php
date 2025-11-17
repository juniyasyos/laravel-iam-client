<?php

namespace Juniyasyos\IamClient\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

class IamUserProvisioner
{
    /**
     * Provision user from IAM access token with JIT provisioning.
     *
     * @param string $accessToken JWT access token from IAM
     * @return \Illuminate\Database\Eloquent\Model
     * @throws \Exception
     */
    public function provisionFromToken(string $accessToken)
    {
        $secret = config('iam.jwt_secret');
        $guardName = config('iam.guard', 'web');
        $userModel = config('iam.user_model');

        // 1. Decode and verify JWT
        try {
            $payload = (array) JWT::decode(
                $accessToken,
                new Key($secret, 'HS256')
            );
        } catch (\Exception $e) {
            Log::error('IAM JWT decode failed', [
                'error' => $e->getMessage(),
            ]);
            abort(403, 'Invalid or expired token: ' . $e->getMessage());
        }

        // 2. Validate token type
        if (($payload['type'] ?? null) !== 'access') {
            Log::warning('IAM token validation failed: invalid type', [
                'type' => $payload['type'] ?? null,
            ]);
            abort(403, 'Invalid token type. Expected "access" token.');
        }

        // 3. Validate app_key
        if (($payload['app_key'] ?? null) !== config('iam.app_key')) {
            Log::warning('IAM token validation failed: app_key mismatch', [
                'expected' => config('iam.app_key'),
                'received' => $payload['app_key'] ?? null,
            ]);
            abort(403, 'Token not valid for this application.');
        }

        // 4. Build user data from configured field mapping
        $userFields = config('iam.user_fields', [
            'iam_id' => 'sub',
            'name' => 'name',
            'email' => 'email',
        ]);

        $identifierField = config('iam.identifier_field', 'iam_id');
        $identifierJwtField = $userFields[$identifierField] ?? 'sub';
        $identifierValue = $payload[$identifierJwtField] ?? null;

        if (!$identifierValue) {
            abort(403, "Token missing required identifier field: {$identifierJwtField}");
        }

        // Build user data array from field mapping
        $userData = [];
        foreach ($userFields as $dbColumn => $jwtField) {
            if ($dbColumn === $identifierField) {
                continue; // Skip identifier, used separately
            }
            if (isset($payload[$jwtField])) {
                $userData[$dbColumn] = $payload[$jwtField];
            }
        }

        // Add active flag
        $userData['active'] = true;

        Log::info('IAM user provisioning started', [
            'identifier_field' => $identifierField,
            'identifier_value' => $identifierValue,
            'user_data' => $userData,
        ]);

        // 5. JIT Provisioning: updateOrCreate user
        /** @var \Illuminate\Database\Eloquent\Model $user */
        $user = $userModel::updateOrCreate(
            [$identifierField => $identifierValue],
            $userData
        );

        Log::info('IAM user provisioned', [
            'user_id' => $user->id,
            'created' => $user->wasRecentlyCreated,
        ]);

        // 6. Sync roles from token payload
        if (config('iam.sync_roles', true)) {
            $this->syncRoles($user, $payload);
        }

        // 7. Login user with session preservation option
        $this->loginUser($user, $guardName);

        // 8. Store access token in session if configured
        if (config('iam.store_access_token_in_session', true)) {
            session(['iam_access_token' => $accessToken]);
        }

        return $user;
    }

    /**
     * Login user with optional session ID preservation
     *
     * @param \Illuminate\Database\Eloquent\Model $user
     * @param string $guardName
     * @return void
     */
    protected function loginUser($user, string $guardName): void
    {
        $preserveSession = config('iam.preserve_session_id', true);
        $guard = Auth::guard($guardName);

        if ($preserveSession) {
            // Preserve session ID (no regeneration)
            $sessionId = session()->getId();

            // Manually update session without regeneration
            session()->put(Auth::getName(), $user->getAuthIdentifier());
            $guard->setUser($user);

            // Fire login event
            event(new \Illuminate\Auth\Events\Login($guardName, $user, false));

            Log::info('IAM user logged in (session preserved)', [
                'user_id' => $user->id,
                'guard' => $guardName,
                'session_id_before' => $sessionId,
                'session_id_after' => session()->getId(),
            ]);
        } else {
            // Standard login with session regeneration
            $guard->login($user);

            Log::info('IAM user logged in (standard)', [
                'user_id' => $user->id,
                'guard' => $guardName,
            ]);
        }
    }

    /**
     * Sync roles from token payload to local user using Spatie Permission.
     *
     * @param \Illuminate\Database\Eloquent\Model $user
     * @param array $payload
     * @return void
     */
    protected function syncRoles($user, array $payload): void
    {
        $roles = $payload['roles'] ?? [];

        if (empty($roles)) {
            Log::info('No roles in token payload', ['user_id' => $user->id]);
            return;
        }

        $guardName = config('iam.role_guard_name', 'web');
        $roleSlugs = collect($roles)
            ->pluck('slug')
            ->filter()
            ->unique()
            ->values()
            ->all();

        Log::info('Syncing roles for user', [
            'user_id' => $user->id,
            'roles' => $roleSlugs,
        ]);

        // Create roles if they don't exist
        foreach ($roleSlugs as $slug) {
            Role::findOrCreate($slug, $guardName);
        }

        // Sync roles to user
        $user->syncRoles($roleSlugs);

        Log::info('Roles synced successfully', [
            'user_id' => $user->id,
            'roles' => $roleSlugs,
        ]);
    }
}
