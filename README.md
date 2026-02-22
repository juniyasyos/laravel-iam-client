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

Package ini mendaftarkan beberapa `middleware` siap pakai untuk melindungi route, memverifikasi token, dan mengamankan back‑channel request.

#### Alias middleware yang tersedia

- `iam.auth` — pastikan user ter‑authenticate (kelas: `EnsureAuthenticated`). Menerima optional `guard` parameter: `iam.auth:web` atau `iam.auth:filament`.
- `iam.verify` — verifikasi access token ke endpoint IAM pada tiap request bila diaktifkan (kelas: `VerifyIamToken`).
- `iam.backchannel.verify` — verifikasi signature HMAC pada request back‑channel (kelas: `VerifyIamBackchannelSignature`).

#### Contoh dasar (web)

```php
Route::middleware(['iam.auth:web'])->group(function () {
    Route::get('/dashboard', DashboardController::class);
});
```

Untuk Filament atau guard lain cukup ubah parameter guard:

```php
Route::get('/admin', AdminController::class)->middleware('iam.auth:filament');
```

#### Verifikasi token per‑request (opsional)

- Middleware: `iam.verify` — memanggil `config('iam.verify_endpoint')` untuk memastikan token masih valid.
- Toggle via config: `iam.verify_each_request` (default: `true`).
- Auto‑attach ke group `web` bila `iam.attach_verify_middleware` diset `true`.

Contoh menambahkan verifikasi explicit pada route:

```php
Route::middleware(['iam.verify', 'iam.auth:web'])->group(function () {
    // protected routes
});
```

Untuk API yang meminta JSON, middleware akan mengembalikan respons `401` berformat JSON ketika token tidak valid.

#### Back‑channel / OP‑initiated logout

Gunakan `iam.backchannel.verify` pada endpoint yang menerima notifikasi dari IAM (memverifikasi HMAC SHA256).

> **Development tip:** jika Anda tidak memerlukan keamanan sama sekali, set
> `IAM_BACKCHANNEL_VERIFY=false`.  Rute back‑channel dan sinkronisasi akan
tetap tersedia, tetapi middleware verifikasi tidak akan dipasang sehingga
semua request diterima.


```php
Route::post('/iam/backchannel', [\Juniyasyos\IamClient\Http\Controllers\BackchannelLogoutController::class, 'handle'])
    ->middleware('iam.backchannel.verify');
```

### Sync endpoints

The package exposes two lightweight API routes that the IAM server uses to
synchronize data from the client application:

```php
Route::middleware(['api', 'iam.backchannel.verify'])->group(function () {
    Route::get('/api/iam/sync-users', \Juniyasyos\IamClient\Http\Controllers\SyncUsersController::class)
        ->name('iam.sync-users');

    Route::get('/api/iam/sync-roles', \Juniyasyos\IamClient\Http\Controllers\SyncRolesController::class)
        ->name('iam.sync-roles');
});
```

Both routes require a valid HMAC signature (see the `iam.backchannel.verify`
middleware) and they accept an `app_key` query parameter which is echoed back.

- **`sync-users`** returns all local users using the fields mapped via
  `config('iam.user_fields')`.  If your user model implements the Spatie
  permission package the `roles` key will also be included.
- **`sync-roles`** returns all available roles (used by the server to keep
  the source of truth in sync).

When registering your application in the IAM server you should point the
appropriate sync URLs to these routes and ensure the shared secret is
configured under `SSO_SECRET`/`sso.secret`.


Signature middleware memeriksa secret dari `config('sso.secret')` atau `env('SSO_SECRET')` dan akan mengembalikan `403` bila tidak valid.

#### Catatan konfigurasi cepat

- `iam.verify_each_request` — aktifkan/disable verifikasi token setiap request.
- `iam.attach_verify_middleware` — bila `true`, package otomatis menambahkan `iam.verify` ke group `web`.
- `iam.require_roles` — tolak sesi jika token tidak mengandung role (dicek oleh `iam.auth`).
- `store_access_token_in_session` — middleware verifikasi membaca token dari session (`iam.access_token`).

> Middleware alias didaftarkan otomatis oleh package (`IamClientServiceProvider`). Anda tidak perlu mendaftarkannya manual kecuali ingin override di `app/Http/Kernel.php`.

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
