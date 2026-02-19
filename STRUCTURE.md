# Package Structure

```
packages/juniyasyos/laravel-iam-client/
├── composer.json              # Package dependencies and autoload
├── README.md                  # Full documentation
├── CHANGELOG.md              # Version history
├── CONTRIBUTING.md           # Contribution guidelines
├── LICENSE.md                # MIT License
├── .gitignore               # Git ignore rules
│
├── config/
│   └── iam.php              # Package configuration
│
├── database/
│   └── migrations/
│       └── 2024_01_01_000000_add_iam_columns_to_users_table.php
│
├── routes/
│   └── iam-client.php       # SSO routes (login & callback)
│
├── resources/
│   └── views/
│       ├── callback-handler.blade.php
│       └── components/
│           └── filament-login-button.blade.php
│
└── src/
    ├── IamClientServiceProvider.php    # Main service provider
    │
    ├── Http/
    │   └── Controllers/
    │       ├── SsoLoginRedirectController.php   # Redirect to IAM
    │       └── SsoCallbackController.php        # Handle IAM callback
    │
    ├── Services/
    │   ├── IamClientManager.php        # Guard-aware login orchestration
    │   └── IamUserProvisioner.php      # JIT provisioning service
    │
    ├── Support/
    │   ├── FilamentIntegration.php     # Optional Filament hooks
    │   └── IamConfig.php               # Helper untuk membaca config per guard
    │
    ├── Data/
    │   └── IamLoginResult.php          # DTO hasil autentikasi
    │
    └── Events/
        └── IamAuthenticated.php        # Event saat login sukses
```

## Key Files Description

### composer.json
Package definition dengan dependencies:
- firebase/php-jwt: JWT token verification
- spatie/laravel-permission: Role management
- illuminate/support: Laravel framework support (v10-12)

### config/iam.php
Configuration untuk:
- IAM server URL dan credentials
- Route paths
- User model dan guard settings
- Role synchronization settings

Role enforcement (new):
- `require_roles`: bila diaktifkan, login SSO akan ditolak jika token tidak berisi roles.
- `required_roles`: daftar role yang diperbolehkan (comma-separated via env atau array); token harus memiliki setidaknya satu role yang cocok.

Gunakan setting ini bila aplikasi client perlu mencegah user tanpa role atau tanpa role yang sesuai untuk mengakses aplikasi.

### Migration: add_iam_columns_to_users_table
Menambahkan kolom ke tabel users:
- `iam_id`: Foreign key ke IAM user
- `active`: Status aktif user

### IamClientServiceProvider
Service provider yang:
- Register config
- Load routes
- Load migrations
- Publish assets

### SsoLoginRedirectController
Controller untuk redirect user ke IAM login page dengan:
- Callback URL
- App key
- State management

### SsoCallbackController
Controller untuk handle callback dari IAM:
- Terima token dari IAM (query atau fragment)
- Serahkan ke IamClientManager untuk verifikasi & login
- Redirect ke URL yang diinginkan/guard terkait
- Jika provisioning/otorisasi gagal (contoh: missing identifier, insufficient roles) controller sekarang merender `callback-handler` dengan pesan error — mencegah redirect loop ke SSO login dan menampilkan pesan yang jelas kepada pengguna.

### IamUserProvisioner & IamClientManager
- **IamUserProvisioner**: verifikasi token via endpoint HTTP, mapping payload ke kolom user, serta sinkronisasi role.
- **IamClientManager**: pilih guard, login user, simpan data IAM di session, dan broadcast event `IamAuthenticated`.

## Published Files

Ketika package diinstall dan published:

### To Application
```
config/
└── iam.php                    # Published config (optional)

database/migrations/
└── 2024_01_01_000000_add_iam_columns_to_users_table.php
```

### Auto-loaded
```
routes/
└── iam-client.php routes are automatically loaded
```

## Usage in Application

Package otomatis terdaftar via Laravel auto-discovery:

```json
"extra": {
    "laravel": {
        "providers": [
            "Juniyasyos\\IamClient\\IamClientServiceProvider"
        ]
    }
}
```

No manual registration needed!
