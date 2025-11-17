<?php

namespace Juniyasyos\IamClient\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use App\Http\Controllers\Auth\CustomSessionGuard;

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
        $guard = config('iam.guard', 'web');
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

        // 4. Extract user data from payload
        $iamId = $payload['sub'] ?? null;
        if (!$iamId) {
            abort(403, 'Token missing user identifier (sub).');
        }

        $name = $payload['name'] ?? 'User';
        $email = $payload['email'] ?? null;

        Log::info('IAM user provisioning started', [
            'iam_id' => $iamId,
            'name' => $name,
            'email' => $email,
        ]);

        // 5. JIT Provisioning: updateOrCreate user
        /** @var \Illuminate\Database\Eloquent\Model $user */
        $user = $userModel::updateOrCreate(
            ['iam_id' => $iamId],
            [
                'name' => $name,
                'email' => $email,
                'active' => true,
            ]
        );

        Log::info('IAM user provisioned', [
            'user_id' => $user->id,
            'iam_id' => $iamId,
            'created' => $user->wasRecentlyCreated,
        ]);

        // 6. Sync roles from token payload
        $this->syncRoles($user, $payload);

        // 7. Login user to the application (without regenerating session ID if using CustomSessionGuard)
        $guard = Auth::guard($guard);

        if ($guard instanceof CustomSessionGuard) {
            // Use custom login that preserves session ID (important for IAM)
            $guard->loginWithoutRegeneration($user);
            Log::info('IAM user logged in via CustomSessionGuard (session preserved)', [
                'user_id' => $user->id,
                'guard' => config('iam.guard', 'web'),
            ]);
        } else {
            // Fallback to standard login
            Auth::guard(config('iam.guard', 'web'))->login($user);
            Log::info('IAM user logged in via standard guard', [
                'user_id' => $user->id,
                'guard' => config('iam.guard', 'web'),
            ]);
        }

        // 8. Store access token in session if configured
        if (config('iam.store_access_token_in_session', true)) {
            session(['iam_access_token' => $accessToken]);
        }

        return $user;
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
