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
    'enabled' => env('IAM_ENABLED', false),

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
    | JWT Algorithm and Leeway
    |--------------------------------------------------------------------------
    |
    | Algorithm used to sign JWTs (default HS256) and optional leeway (secs)
    |
    */
    'jwt_algorithm' => env('IAM_JWT_ALGORITHM', 'HS256'),
    'jwt_leeway' => (int) env('IAM_JWT_LEEWAY', 0),

    /*
    |--------------------------------------------------------------------------
    | Optional issuer / audience checks
    |--------------------------------------------------------------------------
    |
    | When set, the middleware will validate the token's `iss` / `aud` claims
    | against these configuration values.
    |
    */
    'issuer' => env('IAM_ISSUER', env('IAM_BASE_URL', null)),
    'audience' => env('IAM_AUDIENCE', null),

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
    'verify_endpoint' => env('IAM_VERIFY_ENDPOINT', 'http://localhost:8000/api/sso/verify'),

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
    | Replace session on SSO callback
    |--------------------------------------------------------------------------
    |
    | When true, an existing local session will be invalidated and replaced
    | with the SSO user if the incoming token represents a different user.
    |
    */
    'replace_session_on_callback' => env('IAM_REPLACE_SESSION_ON_CALLBACK', true),

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
    | Role enforcement (optional)
    |--------------------------------------------------------------------------
    |
    | - `require_roles` when true will reject SSO login if the token contains
    |   no roles.
    | - `required_roles` accepts a comma-separated list (via env) or an array
    |   of role names; when non-empty the token must contain at least one of
    |   these roles for login to succeed.
    |
    */
    'require_roles' => env('IAM_REQUIRE_ROLES', false),
    'required_roles' => env('IAM_REQUIRED_ROLES') ? array_map('trim', explode(',', env('IAM_REQUIRED_ROLES'))) : [],

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
    | Verify token each request
    |--------------------------------------------------------------------------
    |
    | When enabled the client will call the IAM `verify` endpoint on every
    | web request to ensure the stored access token is still valid. If the
    | token is invalid the client will clear the session and redirect to
    | the login page.
    |
    */
    'verify_each_request' => env('IAM_VERIFY_EACH_REQUEST', true),

    /*    |--------------------------------------------------------------------------
    | Auto‑attach verify middleware
    |--------------------------------------------------------------------------
    |
    | When `true` the package will automatically push its `iam.verify`
    | middleware into the application's `web` middleware group. Leave
    | `false` to register the middleware alias only and let the app add it
    | to Kernel manually.
    |
    */
    'attach_verify_middleware' => env('IAM_ATTACH_VERIFY_MIDDLEWARE', false),

    /*    |--------------------------------------------------------------------------
    | Logout Route Name
    |--------------------------------------------------------------------------
    |
    | The route name to redirect after logout.
    |
    */
    'logout_redirect_route' => env('IAM_LOGOUT_REDIRECT', 'home'),

    /*
    |--------------------------------------------------------------------------    | OP‑initiated logout behaviour
    --------------------------------------------------------------------------
    |
    | Controls how the client responds to OP‑initiated (front‑channel) logout
    | requests from the IAM server (`GET /iam/logout`). When true the client
    | will perform a full `auth()->logout()` + session invalidation. If false
    | the plugin will only remove IAM-related session keys (legacy behaviour).
    |
    */
    'logout_on_op_initiated' => env('IAM_LOGOUT_ON_OP_INITIATED', true),

    /*
    --------------------------------------------------------------------------    | Login Route Name  
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
