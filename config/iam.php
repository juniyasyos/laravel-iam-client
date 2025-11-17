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
    'identifier_field' => env('IAM_IDENTIFIER_FIELD', 'iam_id'),

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

];
