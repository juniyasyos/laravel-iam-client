# Laravel IAM Client Release Notes

## v1.0.0 - 2024-01-01

### Overview
`laravel-iam-client` is a Laravel package built for seamless Single Sign-On (SSO) integration with an IAM server. This release delivers a complete foundation for authentication, user provisioning, JWT validation, and role synchronization.

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
composer require juniyasyos/laravel-iam-client
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
