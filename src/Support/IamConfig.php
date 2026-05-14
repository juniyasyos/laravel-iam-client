<?php

namespace Juniyasyos\IamClient\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class IamConfig
{
    public static function guardConfig(string $guard): array
    {
        $guardConfig = config("iam.guards." . $guard, []);

        $guardConfig['guard'] = $guardConfig['guard'] ?? config('iam.guard', 'web');
        $guardConfig['redirect_route'] = $guardConfig['redirect_route'] ?? config('iam.default_redirect_after_login', '/');
        $guardConfig['login_route_name'] = $guardConfig['login_route_name'] ?? config('iam.login_route_name', 'login');
        $guardConfig['logout_redirect_route'] = $guardConfig['logout_redirect_route'] ?? config('iam.logout_redirect_route', 'home');

        return $guardConfig;
    }

    public static function guardName(string $guard): string
    {
        return (string) data_get(self::guardConfig($guard), 'guard', 'web');
    }

    public static function guardRedirect(string $guard): string
    {
        $redirect = data_get(self::guardConfig($guard), 'redirect_route');

        return $redirect !== null && $redirect !== ''
            ? (string) $redirect
            : (string) config('iam.default_redirect_after_login', '/');
    }

    public static function loginRouteName(string $guard): string
    {
        return (string) data_get(self::guardConfig($guard), 'login_route_name', 'login');
    }

    public static function logoutRedirectRoute(string $guard): ?string
    {
        return data_get(self::guardConfig($guard), 'logout_redirect_route');
    }

    public static function baseUrl(): string
    {
        return rtrim((string) config('iam.base_url'), '/');
    }

    public static function verifyEndpoint(): string
    {
        $explicit = (string) config('iam.verify_endpoint');

        if ($explicit) {
            return $explicit;
        }

        $service = (string) config('services.iam.verify');

        if ($service) {
            return $service;
        }

        $host = self::baseUrl();

        return $host . '/api/verify';
    }

    public static function userApplicationsEndpoint(): string
    {
        $explicit = (string) config('iam.user_applications_endpoint');

        if ($explicit) {
            return $explicit;
        }

        $service = (string) config('services.iam.user_applications');

        if ($service) {
            return $service;
        }

        $host = self::baseUrl();

        return $host . '/api/users/applications';
    }

    public static function userApplicationsDetailEndpoint(): string
    {
        $explicit = (string) config('iam.user_applications_detail_endpoint');

        if ($explicit) {
            return $explicit;
        }

        $service = (string) config('services.iam.user_applications_detail');

        if ($service) {
            return $service;
        }

        $host = self::baseUrl();

        return $host . '/api/users/applications/detail';
    }

    public static function backchannelUserApplicationsEndpoint(): ?string
    {
        $explicit = (string) config('iam.backchannel_user_applications_endpoint');

        return $explicit !== '' ? $explicit : null;
    }

    public static function refreshTokenEndpoint(): string
    {
        $explicit = (string) config('iam.refresh_token_endpoint');

        if ($explicit) {
            return $explicit;
        }

        $service = (string) config('services.iam.refresh_token');

        if ($service) {
            return $service;
        }

        $host = self::baseUrl();

        return $host . '/api/sso/token/refresh';
    }

    public static function appKey(): string
    {
        return (string) config('iam.app_key', 'client-app');
    }

    public static function callbackRouteName(string $guard): string
    {
        if ($guard === 'web') {
            return 'iam.sso.callback';
        }

        return sprintf('iam.sso.callback.%s', $guard);
    }

    public static function loginRouteNamePublic(string $guard): string
    {
        if ($guard === 'web') {
            return 'iam.sso.login';
        }

        return sprintf('iam.sso.login.%s', $guard);
    }

    public static function logoutRouteName(string $guard): string
    {
        if ($guard === 'web') {
            return 'iam.logout';
        }

        return sprintf('iam.logout.%s', $guard);
    }


    /**
     * Whether the user‑sync endpoint should be active.  This check first
     * looks for a database-backed setting so that administrators can toggle
     * the flag at runtime; it falls back to the regular config value so that
     * existing installations continue to work and tests can rely on the
     * environment variable.
     */
    public static function syncUsersEnabled(): bool
    {
        if (Schema::hasTable('iam_settings')) {
            $value = \DB::table('iam_settings')->value('sync_users');

            if ($value !== null) {
                return (bool) $value;
            }
        }

        return (bool) config('iam.sync_users', true);
    }
}
