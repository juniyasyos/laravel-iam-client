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
    public function __construct(private readonly IamUserProvisioner $provisioner)
    {
    }

    public function loginWithToken(string $token, string $guard = 'web'): IamLoginResult
    {
        [$user, $payload] = $this->provisioner->provisionFromToken($token);

        $guardName = IamConfig::guardName($guard);
        $guardInstance = $this->guard($guardName);

        if (! config('iam.preserve_session_id', true)) {
            session()->regenerate();
        }

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
