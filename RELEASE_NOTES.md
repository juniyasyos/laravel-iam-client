# Auth Bridge Client Release Notes

## v1.2.0 - 2026-04-25

### Overview
Pembaruan ini memperkuat `auth-bridge-client` sebagai paket Laravel yang fokus pada sisi client IAM:

- memisahkan dukungan Filament dan menjadikan package bebas Filament
- menyatukan logika unit kerja langsung ke client
- memperbaiki sinkronisasi `status` user (bukan `active`)
- menambahkan endpoint sinkronisasi Unit Kerja yang lebih ringan
- memperjelas konfigurasi dan penggunaan di klien Laravel

### Highlights
- **Non-Filament client mode:** tidak perlu memasang Filament untuk menggunakan package
- **Unit Kerja sync:** support `GET /api/manage-unit-kerja/center/provision`, `POST /api/manage-unit-kerja/client/sync`, dan push sync
- **Status-based user provisioning:** `status` sekarang digunakan sebagai sumber kebenaran untuk `active/inactive/suspended`
- **Cleaner package identity:** menggunakan nama package `juniyasyos/auth-bridge-client`
- **Lebih jelas bagi client developers:** dokumentasi dan route yang langsung relevan untuk aplikasi Laravel client

### Client usage
- `composer require juniyasyos/auth-bridge-client`
- publish config dengan `php artisan vendor:publish --tag=iam-config`
- jalankan migration
- atur `IAM_APP_KEY`, `IAM_JWT_SECRET`, `IAM_BASE_URL`
- gunakan middleware `iam.auth:web` dan route `route('iam.sso.login')`

### Technical details
- **PHP compatibility:** ^8.1
- **Laravel compatibility:** ^10.0 | ^11.0 | ^12.0
- **Dependencies:**
  - `firebase/php-jwt`
  - `spatie/laravel-permission` (opsional)

### Notes
Update ini membuat `auth-bridge-client` lebih siap dipasang di aplikasi Laravel tanpa ketergantungan Filament, serta memperkuat alur SSO, sinkronisasi user, dan unit kerja.

## v1.0.0 - 2024-01-01

### Overview
`auth-bridge-client` is a Laravel package built for seamless Single Sign-On (SSO) integration with an IAM server. This release delivers a complete foundation for authentication, user provisioning, JWT validation, and role synchronization.

### Key Features
- **SSO Integration** with IAM server
- **Just-In-Time (JIT) user provisioning**
- **JWT token verification** via IAM endpoint
- **Role synchronization** with Spatie Permission
- **Auto-registration of routes and migrations**

### Highlights
- Minimal setup required for quick integration
- Automatic user creation and updates during login
- Secure token verification for each authenticated request
- Optional role synchronization for Spatie Permission users
- Support for multi-guard SSO configuration

### Technical Details
- **PHP compatibility:** ^8.1
- **Laravel compatibility:** ^10.0 | ^11.0 | ^12.0
- **Dependencies:**
  - `firebase/php-jwt` for JWT handling
  - `spatie/laravel-permission` for role synchronization support

### Installation
```bash
composer require juniyasyos/auth-bridge-client
php artisan migrate
php artisan vendor:publish --tag=iam-config
```

### Recommended Configuration
Set the following environment variables in your application:

```env
IAM_APP_KEY=your-app-key
IAM_JWT_SECRET=your-jwt-secret
IAM_BASE_URL=https://iam.example.com
```

Optional settings:

```env
IAM_VERIFY_ENDPOINT=https://iam.example.com/api/verify
IAM_PRESERVE_SESSION_ID=true
IAM_SYNC_ROLES=true
```

### Benefits
- Accelerates IAM SSO integration for Laravel applications
- Reduces manual onboarding work for new users
- Ensures consistent logout behavior through IAM
- Provides an extensible base for advanced IAM workflows

### Notes
This initial release is intended as a production-ready client package for Laravel applications that need IAM-based authentication, token verification, and optional role sync support.
