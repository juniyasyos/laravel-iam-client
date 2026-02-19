<?php

namespace Juniyasyos\IamClient\Services;

use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Juniyasyos\IamClient\Data\IamLoginResult;
use Juniyasyos\IamClient\Events\IamAuthenticated;
use Juniyasyos\IamClient\Exceptions\IamAuthenticationException;
use Juniyasyos\IamClient\Support\IamConfig;

class IamClientManager
{
    public function __construct(private readonly IamUserProvisioner $provisioner) {}

    public function loginWithToken(string $token, string $guard = 'web'): IamLoginResult
    {
        [$user, $payload] = $this->provisioner->provisionFromToken($token);

        $guardName = IamConfig::guardName($guard);
        $guardInstance = $this->guard($guardName);

        // Jika ada sesi lokal berbeda, ganti dengan user dari token SSO (opsi konfigurasi)
        if (config('iam.replace_session_on_callback', true) && $guardInstance->check()) {
            $identifier = config('iam.identifier_field', 'email');
            $currentUser = $guardInstance->user();
            $currentValue = data_get($currentUser, $identifier);
            $incomingValue = data_get($user, $identifier);

            if ($currentValue && $incomingValue && $currentValue !== $incomingValue) {
                Log::info('Replacing existing session with SSO user', [
                    'current_identifier' => $currentValue,
                    'incoming_identifier' => $incomingValue,
                    'guard' => $guardName,
                    'session_id' => session()->getId(),
                ]);

                $guardInstance->logout();
                session()->invalidate();
            }
        }

        if (! config('iam.preserve_session_id', true)) {
            session()->regenerate();
        }

        // --- Role enforcement (optional, configurable) --------------------------------
        $tokenRoles = array_filter(array_map(function ($r) {
            if (is_array($r)) {
                return $r['slug'] ?? $r['name'] ?? null;
            }

            return is_string($r) ? $r : null;
        }, $payload['roles'] ?? []));

        // If the application requires that tokens carry at least one role, reject otherwise
        if (config('iam.require_roles', false) && empty($tokenRoles)) {
            Log::warning('IAM callback rejected: token contains no roles but roles are required', [
                'user_sub' => $payload['sub'] ?? null,
                'app' => $payload['app'] ?? null,
                'session_id' => session()->getId(),
            ]);

            throw new IamAuthenticationException('Access denied: no roles assigned in SSO token');
        }

        // If a whitelist of required roles is configured, ensure there is at least one match
        $required = config('iam.required_roles', []);
        if (! empty($required) && empty(array_intersect($required, $tokenRoles))) {
            Log::warning('IAM callback rejected: token does not contain any of the required roles', [
                'required_roles' => $required,
                'token_roles' => $tokenRoles,
                'user_sub' => $payload['sub'] ?? null,
            ]);

            throw new IamAuthenticationException('Access denied: insufficient roles');
        }
        // ------------------------------------------------------------------------------

        $guardInstance->login($user, true);

        if (config('iam.store_access_token_in_session', true)) {
            Session::put('iam.access_token', $token);
        }

        Session::put('iam.payload', $payload);
        Session::put('iam', [
            'sub' => $payload['sub'] ?? null,
            'app' => $payload['app'] ?? null,
            'roles' => $payload['roles'] ?? [],
            'perms' => $payload['perms'] ?? [],
        ]);

        IamAuthenticated::dispatch($user, $payload, $guardName);

        Log::info('IAM authentication completed', [
            'user_id' => $user->getAuthIdentifier(),
            'guard' => $guardName,
            'roles' => $payload['roles'] ?? [],
        ]);

        return new IamLoginResult($user, $payload, $guardName);
    }

    protected function guard(string $guardName): StatefulGuard
    {
        $guard = Auth::guard($guardName);

        if (! $guard instanceof StatefulGuard) {
            throw new IamAuthenticationException("Guard [{$guardName}] is not stateful.");
        }

        return $guard;
    }
}
