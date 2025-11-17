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
└── src/
    ├── IamClientServiceProvider.php    # Main service provider
    │
    ├── Http/
    │   └── Controllers/
    │       ├── SsoLoginRedirectController.php   # Redirect to IAM
    │       └── SsoCallbackController.php        # Handle IAM callback
    │
    └── Services/
        └── IamUserProvisioner.php      # JIT provisioning service
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
- Receive access token
- Provision user via IamUserProvisioner
- Login user
- Redirect to intended URL

### IamUserProvisioner
Service untuk JIT provisioning:
1. Decode & verify JWT token
2. Validate token claims (type, app_key, expiry)
3. Create/update local user
4. Sync roles from token to Spatie Permission
5. Login user to application
6. Store token in session

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
