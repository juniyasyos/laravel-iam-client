<?php

use Illuminate\Support\Facades\Route;
use Juniyasyos\IamClient\Http\Controllers\SsoLoginRedirectController;
use Juniyasyos\IamClient\Http\Controllers\SsoCallbackController;
use Juniyasyos\IamClient\Http\Controllers\LogoutController;
use Juniyasyos\IamClient\Http\Controllers\SyncRolesController;
use Juniyasyos\IamClient\Support\IamConfig;

/*
|--------------------------------------------------------------------------
| IAM Client Routes
|--------------------------------------------------------------------------
|
| These routes handle SSO login flow with IAM server.
|
*/

// API endpoint for IAM role synchronization
Route::middleware('api')->group(function () {
    Route::get('/api/iam/sync-roles', SyncRolesController::class)
        ->name('iam.sync-roles');
});

Route::middleware('web')->group(function () {
    // Redirect to IAM login page
    Route::get(config('iam.login_route', '/sso/login'), SsoLoginRedirectController::class)
        ->name('iam.sso.login')
        ->defaults('guard', 'web');

    // Handle callback from IAM after successful authentication
    Route::match(['GET', 'POST'], config('iam.callback_route', '/sso/callback'), SsoCallbackController::class)
        ->name('iam.sso.callback')
        ->defaults('guard', 'web');

    // Logout
    Route::post('/logout', LogoutController::class)
        ->name('iam.logout')
        ->defaults('guard', 'web');
});

if (IamConfig::filamentEnabled() && class_exists('Filament\\Facades\\Filament')) {
    Route::middleware(IamConfig::filamentConfig('middleware', ['web']))->group(function () {
        Route::get(IamConfig::filamentConfig('login_route', '/filament/sso/login'), SsoLoginRedirectController::class)
            ->name('iam.sso.login.filament')
            ->defaults('guard', 'filament');

        Route::match(['GET', 'POST'], IamConfig::filamentConfig('callback_route', '/filament/sso/callback'), SsoCallbackController::class)
            ->name('iam.sso.callback.filament')
            ->defaults('guard', 'filament');

        $logoutRoute = IamConfig::filamentConfig('logout_route');

        if ($logoutRoute) {
            Route::post($logoutRoute, LogoutController::class)
                ->name('iam.logout.filament')
                ->defaults('guard', 'filament');
        }
    });
}
