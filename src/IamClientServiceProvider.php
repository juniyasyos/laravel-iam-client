<?php

namespace Juniyasyos\IamClient;

use Illuminate\Support\ServiceProvider;
use Juniyasyos\IamClient\Support\FilamentIntegration;

class IamClientServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Merge package config with application config
        $configPath = __DIR__ . '/../config/iam.php';

        if (file_exists($configPath)) {
            $this->mergeConfigFrom($configPath, 'iam');
        }

        // Warn if the configured identifier_field is not present in user_fields mapping.
        // This helps catch misconfigurations like identifier_field=nip without mapping 'nip' => 'nip'.
        $this->app->booted(function () {
            $identifier = config('iam.identifier_field', 'email');
            $fields = array_keys(config('iam.user_fields', []));

            if ($identifier && ! in_array($identifier, $fields, true)) {
                \Illuminate\Support\Facades\Log::warning('IAM config mismatch: identifier_field [' . $identifier . '] is not mapped in iam.user_fields; provisioning may fail.');
            }
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Publish config/migrations/views (always available)
        $this->publishes([
            __DIR__ . '/../config/iam.php' => config_path('iam.php'),
        ], 'iam-config');

        $this->publishes([
            __DIR__ . '/../database/migrations/' => database_path('migrations'),
        ], 'iam-migrations');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/iam-client'),
        ], 'iam-views');

        // If package disabled via config, skip runtime registrations (routes/middleware)
        if (! config('iam.enabled', true)) {
            return;
        }

        // Load routes, migrations and views when enabled
        $this->loadRoutesFrom(__DIR__ . '/../routes/iam-client.php');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'iam-client');

        // Register middleware alias
        $router = $this->app['router'];
        $router->aliasMiddleware('iam.auth', \Juniyasyos\IamClient\Http\Middleware\EnsureAuthenticated::class);
        $router->aliasMiddleware('iam.backchannel.verify', \Juniyasyos\IamClient\Http\Middleware\VerifyIamBackchannelSignature::class);
        $router->aliasMiddleware('iam.verify', \Juniyasyos\IamClient\Http\Middleware\VerifyIamToken::class);

        // Optionally auto-attach the verify middleware to the `web` group when configured
        if (config('iam.attach_verify_middleware', false)) {
            $router->pushMiddlewareToGroup('web', \Juniyasyos\IamClient\Http\Middleware\VerifyIamToken::class);
        }

        FilamentIntegration::boot();
    }
}
