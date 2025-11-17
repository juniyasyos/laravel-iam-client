# Laravel IAM Client

[![Latest Version on Packagist](https://img.shields.io/packagist/v/juniyasyos/laravel-iam-client.svg?style=flat-square)](https://packagist.org/packages/juniyasyos/laravel-iam-client)
[![Total Downloads](https://img.shields.io/packagist/dt/juniyasyos/laravel-iam-client.svg?style=flat-square)](https://packagist.org/packages/juniyasyos/laravel-iam-client)

Package Laravel untuk integrasi Single Sign-On (SSO) dengan IAM server menggunakan JWT token dan JIT (Just-In-Time) user provisioning.

## Fitur

- ✅ **SSO Integration** - Login melalui IAM server dengan OAuth flow
- ✅ **JIT User Provisioning** - User otomatis dibuat/update saat login pertama kali
- ✅ **JWT Token Verification** - Validasi token dengan signature dan claims
- ✅ **Role Synchronization** - Sinkronisasi role dari IAM ke Spatie Permission
- ✅ **Zero Configuration** - Otomatis register routes dan migrations
- ✅ **Flexible** - Support berbagai guard dan user model

## Requirements

- PHP >= 8.1
- Laravel 10.x atau 11.x
- Spatie Laravel Permission

## Installation

### 1. Install Package

```bash
composer require juniyasyos/laravel-iam-client
```

### 2. Install Dependencies (jika belum)

```bash
composer require spatie/laravel-permission
composer require firebase/php-jwt
```

### 3. Publish Configuration (Optional)

```bash
php artisan vendor:publish --tag=iam-config
```

### 4. Configure Environment

Tambahkan konfigurasi IAM di file `.env`:

```env
IAM_APP_KEY=siimut
IAM_JWT_SECRET=your-secret-key-from-iam-server
IAM_BASE_URL=https://iam.example.com
IAM_LOGIN_ROUTE=/sso/login
IAM_CALLBACK_ROUTE=/sso/callback
IAM_DEFAULT_REDIRECT=/panel
IAM_GUARD=web
IAM_ROLE_GUARD_NAME=web
```

### 5. Run Migrations

Migration akan otomatis di-load oleh package. Jalankan:

```bash
php artisan migrate
```

Migration ini akan menambahkan kolom `iam_id` dan `active` ke tabel `users`.

### 6. Update User Model

Pastikan model `User` Anda support Spatie Permission:

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasRoles;

    protected $fillable = [
        'iam_id',
        'name',
        'email',
        'active',
        // ... kolom lain
    ];

    protected $casts = [
        'active' => 'boolean',
    ];
}
```

## Usage

### Routes

Package otomatis register 2 routes:

1. **Login Redirect**: `/sso/login` (route name: `iam.sso.login`)
2. **SSO Callback**: `/sso/callback` (route name: `iam.sso.callback`)

### Redirect User to SSO Login

Untuk mengarahkan user ke IAM login:

```php
return redirect()->route('iam.sso.login');
```

Atau dengan intended URL:

```php
return redirect()->route('iam.sso.login', ['intended' => '/admin/dashboard']);
```

### Integrasi dengan Filament

Jika menggunakan Filament Admin Panel, override login page:

#### Option 1: Custom Login Page

Buat custom login page di `resources/views/filament/pages/login.blade.php`:

```blade
<x-filament-panels::page.simple>
    <div class="text-center">
        <h2 class="text-2xl font-bold mb-4">Login via IAM</h2>
        <a href="{{ route('iam.sso.login', ['intended' => '/panel']) }}" 
           class="inline-flex items-center justify-center px-4 py-2 bg-primary-600 text-white rounded-lg">
            Login dengan IAM
        </a>
    </div>
</x-filament-panels::page.simple>
```

Kemudian di `AdminPanelProvider`:

```php
use App\Filament\Pages\Auth\Login;

public function panel(Panel $panel): Panel
{
    return $panel
        ->login(Login::class)
        // ... config lainnya
}
```

#### Option 2: Middleware Redirect

Atau buat middleware untuk auto-redirect:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RedirectToSsoIfNotAuthenticated
{
    public function handle(Request $request, Closure $next)
    {
        if (!auth()->check() && $request->is('panel*')) {
            return redirect()->route('iam.sso.login', [
                'intended' => $request->fullUrl()
            ]);
        }

        return $next($request);
    }
}
```

### Access Authenticated User

Setelah SSO login berhasil, gunakan seperti biasa:

```php
$user = Auth::user();
$user = auth()->user();

// Check roles (via Spatie Permission)
if ($user->hasRole('admin')) {
    // ...
}

// Check permissions
if ($user->can('view_dashboard')) {
    // ...
}
```

### Access Token

Jika Anda perlu access token untuk API calls ke IAM:

```php
$accessToken = session('iam_access_token');
```

## How It Works

### Flow SSO Login

1. User mengakses `/sso/login`
2. User di-redirect ke IAM server login page
3. User login di IAM server
4. IAM redirect kembali ke `/sso/callback` dengan `access_token`
5. Package decode dan validasi JWT token:
   - Verifikasi signature dengan `IAM_JWT_SECRET`
   - Validasi `type` harus "access"
   - Validasi `app_key` sesuai dengan `IAM_APP_KEY`
   - Check token belum expired
6. **JIT Provisioning**: User lokal dibuat/update berdasarkan `iam_id` (sub dari token)
7. **Role Sync**: Roles dari token di-sync ke Spatie Permission
8. User di-login ke aplikasi dengan guard yang dikonfigurasi
9. Redirect ke intended URL atau default redirect

### Token Payload Structure

Package mengharapkan JWT token dengan payload berikut:

```json
{
  "iss": "iam-server",
  "sub": 123,
  "type": "access",
  "app_key": "siimut",
  "name": "John Doe",
  "email": "john@example.com",
  "roles": [
    {
      "slug": "admin",
      "name": "Administrator"
    },
    {
      "slug": "user",
      "name": "User"
    }
  ],
  "exp": 1234567890
}
```

## Configuration

Semua konfigurasi tersedia di `config/iam.php`:

```php
return [
    'app_key' => env('IAM_APP_KEY', 'siimut'),
    'jwt_secret' => env('IAM_JWT_SECRET', 'change-me'),
    'base_url' => env('IAM_BASE_URL', 'https://iam.example.com'),
    'login_route' => env('IAM_LOGIN_ROUTE', '/sso/login'),
    'callback_route' => env('IAM_CALLBACK_ROUTE', '/sso/callback'),
    'default_redirect_after_login' => env('IAM_DEFAULT_REDIRECT', '/panel'),
    'guard' => env('IAM_GUARD', 'web'),
    'user_model' => env('IAM_USER_MODEL', 'App\\Models\\User'),
    'role_guard_name' => env('IAM_ROLE_GUARD_NAME', 'web'),
    'store_access_token_in_session' => env('IAM_STORE_TOKEN_IN_SESSION', true),
];
```

## Security

### Best Practices

- ✅ Simpan `IAM_JWT_SECRET` di `.env` dan jangan commit
- ✅ Gunakan HTTPS di production
- ✅ Validasi semua JWT claims (iss, aud, app_key, exp)
- ✅ Regenerate session setelah login (otomatis oleh Laravel)
- ✅ Consider disable local password login jika wajib SSO

### CSRF Protection

Route callback menggunakan `match(['GET', 'POST'])` untuk flexibility. Jika menggunakan POST, pastikan CSRF token di-handle atau exclude route dari CSRF verification jika IAM callback tidak support CSRF token.

Di `app/Http/Middleware/VerifyCsrfToken.php`:

```php
protected $except = [
    '/sso/callback',
];
```

## Testing

```bash
composer test
```

## Troubleshooting

### Error: "Invalid or expired token"

- Pastikan `IAM_JWT_SECRET` sama dengan secret di IAM server
- Check token belum expired
- Pastikan algoritma JWT adalah HS256

### Error: "Token not valid for this application"

- Pastikan `IAM_APP_KEY` di `.env` sama dengan `app_key` di token payload

### User tidak ter-login setelah callback

- Check log di `storage/logs/laravel.log`
- Pastikan guard yang dikonfigurasi sesuai
- Pastikan session middleware aktif

### Roles tidak sync

- Pastikan Spatie Permission sudah di-install dan migrasi sudah dijalankan
- Check `roles` ada di token payload dengan format yang benar
- Pastikan `role_guard_name` sesuai dengan guard yang digunakan

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
