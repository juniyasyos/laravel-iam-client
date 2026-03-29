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

// API endpoints for IAM synchronization.  Routes may be wrapped in the
// back-channel verification middleware depending on configuration.  This
// allows easier testing/development when you just need the URL but don't
// want to bother with generating a valid signature or token.

$middleware = ['api'];
if (config('iam.backchannel_verify', true)) {
    $middleware[] = 'iam.backchannel.verify';
}

Route::middleware($middleware)->group(function () {
    // returned JSON structure matches what the server's sync services expect

    if (\Juniyasyos\IamClient\Support\IamConfig::syncUsersEnabled()) {
        // only expose the user-sync endpoint when enabled; otherwise the route
        // is not registered and any incoming request will receive a 404.
        Route::get('/api/iam/sync-users', \Juniyasyos\IamClient\Http\Controllers\SyncUsersController::class)
            ->name('iam.sync-users');
    }

    Route::match(['GET', 'POST'], '/api/iam/sync-roles', SyncRolesController::class)
        ->name('iam.sync-roles');

    // Incoming role sync from IAM to this client (IAM pushes authoritative role set)
    Route::post('/api/iam/push-roles', \Juniyasyos\IamClient\Http\Controllers\PushRolesController::class)
        ->name('iam.push-roles');
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

    // Fetch current user's IAM applications from IAM server.
    // Requires that the user is already authenticated and has a valid token in session.
    Route::get('/iam/user-applications', \Juniyasyos\IamClient\Http\Controllers\IamUserApplicationsController::class)
        ->name('iam.user-applications')
        ->middleware(['iam.verify'])
        ->defaults('guard', 'web');

    // Debug route: Web-only access to local user app list (no Bearer token needed).
    // Anda bisa buka di browser setelah login dengan user yang valid.
    Route::get('/iam/debug/user-applications', [\Juniyasyos\IamClient\Http\Controllers\IamUserApplicationsController::class, 'webUserApplications'])
        ->name('iam.user-applications.debug')
        ->middleware(['web', 'auth'])
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
