<?php

use Illuminate\Support\Facades\Route;
use Juniyasyos\IamClient\Http\Controllers\SsoLoginRedirectController;
use Juniyasyos\IamClient\Http\Controllers\SsoCallbackController;
use Juniyasyos\IamClient\Http\Controllers\LogoutController;
use Juniyasyos\IamClient\Http\Controllers\SyncRolesController;
use Juniyasyos\IamClient\Http\Controllers\IamInitiatedLogoutController;
use Juniyasyos\IamClient\Support\IamConfig;

/*
|--------------------------------------------------------------------------
| IAM Client Routes
|--------------------------------------------------------------------------
|
| These routes handle SSO login flow with IAM server.
|
*/

// API endpoints for IAM synchronization.  Both routes are protected by the
// `iam.backchannel.verify` middleware which applies the HMAC signature check
// documented in the package README and the server-side docs.
Route::middleware(['api', 'iam.backchannel.verify'])->group(function () {
    // returned JSON structure matches what the server's sync services expect
    Route::get('/api/iam/sync-users', \Juniyasyos\IamClient\Http\Controllers\SyncUsersController::class)
        ->name('iam.sync-users');

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

    // Public endpoint for OP‑initiated (global) logout called by IAM.
    // Clears only IAM-related session keys so client can sign-out silently.
    Route::get('/iam/logout', IamInitiatedLogoutController::class)
        ->name('iam.iam.logout')
        ->defaults('guard', 'web');

    // OP → client back‑channel logout (server→server). Verifies HMAC signature.
    Route::post('/iam/backchannel-logout', \Juniyasyos\IamClient\Http\Controllers\BackchannelLogoutController::class)
        ->name('iam.backchannel.logout')
        ->middleware('iam.backchannel.verify')
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
