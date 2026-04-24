# Laravel IAM Client

Laravel package for IAM Single Sign-On (SSO) integration using JWT verification and Just-In-Time (JIT) user provisioning.

## Features

- ✅ **Zero configuration** — minimal setup and ready to use
- ✅ **Guard-aware SSO routes** — support multiple guards such as `web` and `filament`
- ✅ **OP-initiated logout** — public endpoint at `/iam/logout` for browser-based logout requests from IAM
- ✅ **JIT user provisioning** — automatic user creation and updates during login
- ✅ **JWT token verification** — validate access tokens using IAM endpoints
- ✅ **Role synchronization** — optional integration with Spatie Permission
- ✅ **Flexible field mapping** — map custom user fields like `nip`, `nik`, `employee_id`
- ✅ **Session preservation** — retain session ID during login
- ✅ **Optional Filament support** — add a “Login via IAM” button to Filament login screens

## Requirements

- PHP `^8.1`
- Laravel `^10.0 | ^11.0 | ^12.0`
- `firebase/php-jwt`
- `spatie/laravel-permission` (optional, only for role synchronization)

## Installation

```bash
composer require juniyasyos/laravel-iam-client
php artisan migrate
php artisan vendor:publish --tag=iam-config
```

## Quick Start

### 1. Environment Variables

```env
IAM_APP_KEY=your-app-key
IAM_JWT_SECRET=your-jwt-secret
IAM_BASE_URL=https://iam.example.com
# Optional
IAM_VERIFY_ENDPOINT=https://iam.example.com/api/verify
IAM_PRESERVE_SESSION_ID=true
IAM_SYNC_ROLES=true
```

### 2. User Model

```php
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasRoles;

    protected $fillable = [
        'iam_id',
        'name',
        'email',
        'active',
    ];
}
```

### 3. Routes and Middleware

This package registers ready-to-use middleware for authentication, token verification, and back-channel request validation.

#### Available middleware aliases

- `iam.auth` — ensures the user is authenticated. Accepts an optional `guard` parameter, such as `iam.auth:web` or `iam.auth:filament`.
- `iam.verify` — verifies the access token with the IAM verification endpoint, if enabled.
- `iam.backchannel.verify` — verifies the HMAC signature on back-channel requests.

#### Basic web example

```php
Route::middleware(['iam.auth:web'])->group(function () {
    Route::get('/dashboard', DashboardController::class);
});
```

To use a different guard, change the parameter:

```php
Route::get('/admin', AdminController::class)->middleware('iam.auth:filament');
```

#### Per-request token verification (optional)

- Middleware: `iam.verify` checks `config('iam.verify_endpoint')` to validate the token on each request.
- Config toggle: `iam.verify_each_request` (default: `true`).
- Automatically attach to the `web` group when `iam.attach_verify_middleware` is `true`.

Example:

```php
Route::middleware(['iam.verify', 'iam.auth:web'])->group(function () {
    // protected routes
});
```

When a JSON API request has an invalid token, the middleware returns a `401` JSON response.

#### OP-initiated logout

A public logout endpoint is available at `/iam/logout` for IAM-initiated browser logout requests. The package handles a full `auth()->logout()` and session invalidation.

### Livewire App Switcher

The package includes a reusable Livewire component for displaying the current user's accessible IAM applications.

#### Requirements

- Livewire must be installed in the client application.
- `config('iam.enabled')` must be `true`.
- The IAM session must contain a valid access token under `iam.access_token` or `iam.access_token_backup`.

#### Usage

In any Blade view, render the component with:

```blade
@livewire('iam-app-switcher')
```

This component will:

- fetch the current user's applications from IAM
- cache the result for 5 minutes
- show a dropdown with app logo, name, and active status
- navigate to the selected application URL

#### Custom view override

If you need to override the package view in your application, publish the package views:

```bash
php artisan vendor:publish --tag=iam-views
```

Then copy and customize the file from `resources/views/vendor/iam-client/livewire/iam-app-switcher.blade.php`.

### Sync Endpoints

The package provides lightweight API routes for IAM to synchronize client application data:

```php
Route::middleware(['api', 'iam.backchannel.verify'])->group(function () {
    Route::get('/api/iam/sync-users', \Juniyasyos\IamClient\Http\Controllers\SyncUsersController::class)
        ->name('iam.sync-users');

    Route::get('/api/iam/sync-roles', \Juniyasyos\IamClient\Http\Controllers\SyncRolesController::class)
        ->name('iam.sync-roles');
});
```

Both routes require a valid HMAC signature and accept an `app_key` query parameter.

- `sync-users` returns local users using fields defined in `config('iam.user_fields')`. If Spatie Permission is enabled, the `roles` key is included.
- `sync-roles` returns available roles so IAM can keep the source of truth synchronized.
- `push-roles` is the reverse flow: IAM posts the authoritative role list to the client.

When registering your app in IAM, point the sync URLs to these routes and configure the shared secret in `SSO_SECRET` / `sso.secret`.

### Configuration Notes

- `iam.verify_each_request` — enable or disable per-request token verification.
- `iam.attach_verify_middleware` — automatically attach `iam.verify` to the `web` group.
- `iam.require_roles` — reject sessions when the token does not contain roles.
- `iam.unit_kerja_field` — JWT claim name for the user’s unit/org field.
- `iam.require_unit_kerja` — reject login when the unit/org claim is missing.
- `iam.sync_unit_kerja` — synchronize the `unitKerjas()` relation on the user model after provisioning.
- `iam.unit_kerja_model` — Eloquent model used for unit/org data (default: `App\\Models\\UnitKerja`).
- `store_access_token_in_session` — store the access token in session under `iam.access_token`.

> Middleware aliases are registered automatically by `IamClientServiceProvider`. Manual registration in `app/Http/Kernel.php` is usually not required.

```blade
<a href="{{ route('iam.sso.login') }}">Login via IAM</a>
```

The package also registers these SSO routes:

- `iam.sso.login` — redirects to IAM
- `iam.sso.callback` — handles the token response
- `iam.logout` — performs logout and session cleanup

## Custom Field Mapping

```php
// config/iam.php
'user_fields' => [
    'iam_id' => 'sub',
    'name' => 'name',
    'email' => 'email',
    'nip' => 'nip',
    'nik' => 'nik',
],
'identifier_field' => 'iam_id',
```

## Token Payload Example

```json
{
  "type": "access",
  "app_key": "your-app-key",
  "sub": 123,
  "name": "John Doe",
  "email": "john@example.com",
  "nip": "123456",
  "roles": [{"slug": "admin"}],
  "exp": 1234567890
}
```

## Multi Guard & Custom Redirect

Configure guards in `config/iam.php`:

```php
'guards' => [
    'web' => [
        'guard' => 'web',
        'redirect_route' => '/',
        'login_route_name' => 'login',
        'logout_redirect_route' => 'home',
    ],
    'filament' => [
        'guard' => 'filament',
        'redirect_route' => '/admin',
        'login_route_name' => 'filament.auth.login',
    ],
],
```

To add a new guard, register your own route and set `defaults('guard', 'your_guard')` or pass the guard parameter to the controller.

## Filament Integration (Optional)

Enable Filament support with these environment variables:

```env
IAM_FILAMENT_ENABLED=true
IAM_FILAMENT_GUARD=filament
IAM_FILAMENT_PANEL=admin
IAM_FILAMENT_LOGIN_ROUTE=/filament/sso/login
IAM_FILAMENT_CALLBACK_ROUTE=/filament/sso/callback
IAM_FILAMENT_LOGIN_BUTTON="Login via IAM"
# Optional: override Filament logout to use IAM controller
# IAM_FILAMENT_LOGOUT_ROUTE=/filament/logout
```

When Filament is enabled:

1. `/filament/sso/login` and `/filament/sso/callback` routes are generated.
2. A "Login via IAM" button appears on the Filament login page.
3. Filament logout can be routed through IAM when `IAM_FILAMENT_LOGOUT_ROUTE` is configured.

> For non-Filament apps, set `IAM_FILAMENT_ENABLED=false` and the package works normally.

## Event Hooks

A successful login dispatches the `IamAuthenticated` event. Listen to this event for auditing, downstream provisioning, or custom logging.

```php
use Juniyasyos\IamClient\Events\IamAuthenticated;

Event::listen(IamAuthenticated::class, function ($event) {
    // $event->user, $event->payload, $event->guard
});
```

## License

MIT
