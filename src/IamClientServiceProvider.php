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
        // Load routes
        $this->loadRoutesFrom(__DIR__ . '/../routes/iam-client.php');

        // Load migrations
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Load views
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'iam-client');

        // Publish config
        $this->publishes([
            __DIR__ . '/../config/iam.php' => config_path('iam.php'),
        ], 'iam-config');

        // Publish migrations
        $this->publishes([
            __DIR__ . '/../database/migrations/' => database_path('migrations'),
        ], 'iam-migrations');

        // Publish views
        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/iam-client'),
        ], 'iam-views');

        // Register middleware alias
        $router = $this->app['router'];
        $router->aliasMiddleware('iam.auth', \Juniyasyos\IamClient\Http\Middleware\EnsureAuthenticated::class);
        $router->aliasMiddleware('iam.backchannel.verify', \Juniyasyos\IamClient\Http\Middleware\VerifyIamBackchannelSignature::class);

        FilamentIntegration::boot();
    }
}
