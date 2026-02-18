# Laravel IAM Client

Package Laravel untuk integrasi Single Sign-On (SSO) dengan IAM server menggunakan JWT token dan JIT (Just-In-Time) user provisioning.

## Fitur

- ✅ **Zero Configuration** – Minimal setup, langsung pakai
- ✅ **Guard-aware SSO Routes** – Jalankan beberapa guard sekaligus (web/Filament/dsb)
- ✅ **OP‑initiated logout (`/iam/logout`)** – Public endpoint tersedia; IAM dapat mengarahkan browser ke `/iam/logout` (mendukung `post_logout_redirect`).

  Configuration: `logout_on_op_initiated` (default: `true`) — when enabled the plugin will perform a full `auth()->logout()` and invalidate the session when receiving an OP‑initiated logout. Set to `false` to preserve the legacy behaviour of only clearing IAM-related session keys.
- ✅ **JIT User Provisioning** – User otomatis dibuat/update sesuai mapping
- ✅ **JWT Token Verification** – Validasi token via endpoint IAM
- ✅ **Role Synchronization** – Sinkronisasi role ke Spatie Permission (opsional)
- ✅ **Flexible Field Mapping** – Mapping bebas (nip, nik, employee_id, dll)
- ✅ **Session Preservation** – Menjaga session ID saat login
- ✅ **Filament Hooks (Opsional)** – Tombol “Login via IAM” langsung di layar login panel Filament

## Installation

```bash
composer require juniyasyos/laravel-iam-client
php artisan migrate
```

### Publish Configuration

```bash
php artisan vendor:publish --tag=iam-config
```
## Quick Start

### 1. Environment Variables

```env
IAM_APP_KEY=your-app-key
IAM_JWT_SECRET=your-jwt-secret
IAM_BASE_URL=https://iam.example.com
# Opsional
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
    
    protected $fillable = ['iam_id', 'name', 'email', 'active'];
}
```

### 3. Gunakan Middleware & Route

```php
Route::middleware(['iam.auth:web'])->group(function () {
    Route::get('/dashboard', DashboardController::class);
});
```

```blade
<a href="{{ route('iam.sso.login') }}">Login via IAM</a>
```

Semua route SSO otomatis tersedia:

- `iam.sso.login` → redirect ke IAM
- `iam.sso.callback` → menerima token
- `iam.logout` → keluar & bersihkan sesi

## Custom Field Mapping

```php
// config/iam.php
'user_fields' => [
    'iam_id' => 'sub',
    'name' => 'name',
    'email' => 'email',
    'nip' => 'nip',         // Custom field
    'nik' => 'nik',         // Custom field
],
'identifier_field' => 'iam_id',
```

## Token Payload

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

Atur guard tertentu di `config/iam.php`:

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

Tambahkan guard baru? Cukup register route sendiri dan beri `defaults('guard', 'nama_guard')` atau panggil controller dengan parameter guard.

## Filament Integration (Opsional)

Aktifkan dengan ENV berikut:

```env
IAM_FILAMENT_ENABLED=true
IAM_FILAMENT_GUARD=filament
IAM_FILAMENT_PANEL=admin
IAM_FILAMENT_LOGIN_ROUTE=/filament/sso/login
IAM_FILAMENT_CALLBACK_ROUTE=/filament/sso/callback
IAM_FILAMENT_LOGIN_BUTTON="Login via IAM"
# Opsional: override route logout Filament agar memakai controller IAM
# IAM_FILAMENT_LOGOUT_ROUTE=/filament/logout
```

Ketika Filament tersedia:

1. Route `/filament/sso/login` & `/filament/sso/callback` otomatis dibuat.
2. Tombol "Login via IAM" tampil di halaman login panel.
3. Logout panel dapat diarahkan ke route IAM (`iam.logout.filament`) bila Anda menentukan `IAM_FILAMENT_LOGOUT_ROUTE` sendiri.

> Non-Filament project? Biarkan `IAM_FILAMENT_ENABLED=false` dan package tetap bekerja seperti biasa.

## Event Hooks

Setiap login sukses mem-broadcast event `IamAuthenticated`. Anda bisa mendengarkan event ini untuk audit logging, provisioning ke service lain, dsb.

```php
use Juniyasyos\IamClient\Events\IamAuthenticated;

Event::listen(IamAuthenticated::class, function ($event) {
    // $event->user, $event->payload, $event->guard
});
```

## License

MIT
