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

    public static function filamentEnabled(): bool
    {
        return (bool) config('iam.filament.enabled', false);
    }

    public static function filamentConfig(?string $key = null, $default = null)
    {
        $config = config('iam.filament', []);

        if ($key === null) {
            return $config;
        }

        return data_get($config, $key, $default);
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

    /**
     * Whether the user‑sync endpoint should be active.  This check first
     * looks for a database-backed setting so that administrators can toggle
     * the flag at runtime; it falls back to the regular config value so that
     * existing installations continue to work and tests can rely on the
     * environment variable.
     */
    public static function syncUsersEnabled(): bool
    {
        // we do a very light touch to avoid needing the schema builder all the
        // time, but the migration ensures the table will exist once the package
        // is set up.
        if (app()->runningInConsole() === false && 
            \