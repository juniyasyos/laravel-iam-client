<?php

namespace Juniyasyos\IamClient\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class FilamentIntegration
{
    public static function boot(): void
    {
        // Check if filament is enabled in config
        if (! config('iam.filament.enabled', false)) {
            return;
        }

        if (! class_exists('Filament\\Facades\\Filament')) {
            Log::warning('IAM Filament integration enabled but Filament is not installed.');

            return;
        }

        try {
            \Filament\Facades\Filament::serving(function ($event) {
                $panel = method_exists($event, 'getPanel') ? $event->getPanel() : null;
                $allowedPanels = Arr::wrap(config('iam.filament.panel', 'admin'));

                if ($panel && $allowedPanels && ! in_array($panel->getId(), $allowedPanels, true)) {
                    return;
                }

                \Filament\Facades\Filament::registerRenderHook('auth.login.form.after', function () {
                    return view('iam-client::components.filament-login-button');
                });
            });
        } catch (\Exception $e) {
            Log::error('Failed to register IAM Filament integration: ' . $e->getMessage());
        }
    }
}
