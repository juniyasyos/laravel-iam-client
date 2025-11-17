<?php

return [

    /*
    |--------------------------------------------------------------------------
    | IAM Application Key
    |--------------------------------------------------------------------------
    |
    | This is the application key registered in IAM server. The access token
    | must contain this app_key in the payload for validation.
    |
    */
    'app_key' => env('IAM_APP_KEY', 'client-app'),

    /*
    |--------------------------------------------------------------------------
    | JWT Secret Key
    |--------------------------------------------------------------------------
    |
    | The secret key used to verify JWT tokens from IAM server.
    | This must match the secret configured in IAM server.
    |
    */
    'jwt_secret' => env('IAM_JWT_SECRET', 'change-me'),

    /*
    |--------------------------------------------------------------------------
    | IAM Base URL
    |--------------------------------------------------------------------------
    |
    | The base URL of your IAM server where users will be redirected for login.
    |
    */
    'base_url' => env('IAM_BASE_URL', 'http://localhost:8000'),

    /*
    |--------------------------------------------------------------------------
    | SSO Routes
    |--------------------------------------------------------------------------
    |
    | Configure the routes for SSO login and callback endpoints.
    |
    */
    'login_route' => env('IAM_LOGIN_ROUTE', '/sso/login'),
    'callback_route' => env('IAM_CALLBACK_ROUTE', '/sso/callback'),

    /*
    |--------------------------------------------------------------------------
    | Default Redirect After Login
    |--------------------------------------------------------------------------
    |
    | Where to redirect users after successful SSO login.
    |
    */
    'default_redirect_after_login' => env('IAM_DEFAULT_REDIRECT', '/'),

    /*
    |--------------------------------------------------------------------------
    | Authentication Guard
    |--------------------------------------------------------------------------
    |
    | The guard to use for authenticating users after SSO login.
    |
    */
    'guard' => env('IAM_GUARD', 'web'),

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    |
    | The User model class used in your application.
    |
    */
    'user_model' => env('IAM_USER_MODEL', 'App\\Models\\User'),

    /*
    |--------------------------------------------------------------------------
    | Role Guard Name
    |--------------------------------------------------------------------------
    |
    | The guard name to use when creating/syncing roles with Spatie Permission.
    |
    */
    'role_guard_name' => env('IAM_ROLE_GUARD_NAME', 'web'),

    /*
    |--------------------------------------------------------------------------
    | Store Access Token in Session
    |--------------------------------------------------------------------------
    |
    | Whether to store the IAM access token in the session after login.
    | This can be useful for making API calls to IAM server.
    |
    */
    'store_access_token_in_session' => env('IAM_STORE_TOKEN_IN_SESSION', true),

];
