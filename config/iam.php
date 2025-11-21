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
    | Token Verification Endpoint
    |--------------------------------------------------------------------------
    |
    | Optional explicit endpoint for JWT verification. When null, the package
    | will derive it from the IAM base URL.
    |
    */
    'verify_endpoint' => env('IAM_VERIFY_ENDPOINT'),

    /*
    |--------------------------------------------------------------------------
    | Default Web Guard SSO Routes
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
    | Session Preservation
    |--------------------------------------------------------------------------
    |
    | Preserve session ID during login (no regeneration).
    | Set to true for IAM compatibility, false for standard Laravel behavior.
    |
    */
    'preserve_session_id' => env('IAM_PRESERVE_SESSION_ID', true),

    /*
    |--------------------------------------------------------------------------
    | User Field Mapping
    |--------------------------------------------------------------------------
    |
    | Map JWT token fields to user model columns.
    | Add any custom fields your application needs (nip, nik, employee_id, etc)
    |
    | Format: 'database_column' => 'jwt_field'
    |
    */
    'user_fields' => [
        'iam_id' => 'sub',        // Required: JWT sub maps to iam_id
        'name' => 'name',
        'email' => 'email',
        // Add custom mappings:
        // 'nip' => 'nip',
        // 'nik' => 'nik',
        // 'employee_id' => 'employee_id',
        // 'phone' => 'phone_number',
    ],

    /*
    |--------------------------------------------------------------------------
    | Identifier Field
    |--------------------------------------------------------------------------
    |
    | Primary field to identify users (used in updateOrCreate).
    | Usually 'iam_id' or 'email'
    |
    */
    'identifier_field' => env('IAM_IDENTIFIER_FIELD', 'email'),

    /*
    |--------------------------------------------------------------------------
    | Role Synchronization
    |--------------------------------------------------------------------------
    |
    | Enable automatic role sync from IAM token to Spatie Permission
    |
    */
    'sync_roles' => env('IAM_SYNC_ROLES', true),
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

    /*
    |--------------------------------------------------------------------------
    | Logout Route Name
    |--------------------------------------------------------------------------
    |
    | The route name to redirect after logout.
    |
    */
    'logout_redirect_route' => env('IAM_LOGOUT_REDIRECT', 'home'),

    /*
    |--------------------------------------------------------------------------
    | Login Route Name  
    |--------------------------------------------------------------------------
    |
    | The route name for login page (used for unauthenticated redirects).
    |
    */
    'login_route_name' => env('IAM_LOGIN_ROUTE_NAME', 'login'),

    /*
    |--------------------------------------------------------------------------
    | Guard Specific Configuration
    |--------------------------------------------------------------------------
    |
    | Allows overriding guard, redirect, and Filament specific settings per
    | guard. Values fall back to the legacy keys above for backwards
    | compatibility.
    |
    */
    'guards' => [
        'web' => [
            'guard' => env('IAM_GUARD', 'web'),
            'redirect_route' => env('IAM_DEFAULT_REDIRECT', '/'),
            'login_route_name' => env('IAM_LOGIN_ROUTE_NAME', 'login'),
            'logout_redirect_route' => env('IAM_LOGOUT_REDIRECT', 'home'),
        ],
        'filament' => [
            'guard' => env('IAM_FILAMENT_GUARD', 'filament'),
            'redirect_route' => env('IAM_FILAMENT_REDIRECT_ROUTE', null),
            'login_route_name' => env('IAM_FILAMENT_LOGIN_ROUTE_NAME', 'filament.auth.login'),
            'logout_redirect_route' => env('IAM_FILAMENT_LOGOUT_REDIRECT', null),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Filament Integration
    |--------------------------------------------------------------------------
    |
    | When enabled, additional routes, hooks, and UI helpers for Filament will
    | be registered. Disable to keep the package framework agnostic.
    |
    */
    'filament' => [
        'enabled' => env('IAM_FILAMENT_ENABLED', false),
        'panel' => env('IAM_FILAMENT_PANEL', 'admin'),
        'login_route' => env('IAM_FILAMENT_LOGIN_ROUTE', '/filament/sso/login'),
        'callback_route' => env('IAM_FILAMENT_CALLBACK_ROUTE', '/filament/sso/callback'),
        'login_button_text' => env('IAM_FILAMENT_LOGIN_BUTTON', 'Login via IAM'),
        'logout_route' => env('IAM_FILAMENT_LOGOUT_ROUTE'),
        'middleware' => ['web'],
    ],

];
