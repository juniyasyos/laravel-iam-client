# Laravel IAM Client

Package Laravel untuk integrasi Single Sign-On (SSO) dengan IAM server menggunakan JWT token dan JIT (Just-In-Time) user provisioning.

## Fitur

- ✅ **Zero Configuration** - Minimal setup, langsung pakai
- ✅ **SSO Integration** - Login via IAM server
- ✅ **JIT User Provisioning** - User otomatis dibuat/update
- ✅ **JWT Token Verification** - Validasi token
- ✅ **Role Synchronization** - Sinkronisasi role dari IAM
- ✅ **Flexible Field Mapping** - Support custom fields (nip, nik, dll)
- ✅ **Session Preservation** - Compatible dengan IAM session handling
- ✅ **Auto-register Routes** - Routes otomatis tersedia

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

### 3. Use It!

```blade
<a href="{{ route('iam.sso.login') }}">Login via IAM</a>
```

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

## License

MIT
