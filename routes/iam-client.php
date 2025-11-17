<?php

use Illuminate\Support\Facades\Route;
use Juniyasyos\IamClient\Http\Controllers\SsoLoginRedirectController;
use Juniyasyos\IamClient\Http\Controllers\SsoCallbackController;

/*
|--------------------------------------------------------------------------
| IAM Client Routes
|--------------------------------------------------------------------------
|
| These routes handle SSO login flow with IAM server.
|
*/

Route::middleware('web')->group(function () {
    // Redirect to IAM login page
    Route::get(config('iam.login_route', '/sso/login'), SsoLoginRedirectController::class)
        ->name('iam.sso.login');

    // Handle callback from IAM after successful authentication
    Route::match(['GET', 'POST'], config('iam.callback_route', '/sso/callback'), SsoCallbackController::class)
        ->name('iam.sso.callback');
});
