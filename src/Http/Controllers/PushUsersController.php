<?php

namespace Juniyasyos\IamClient\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class PushUsersController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $mode = config('iam.user_sync_mode', 'pull');
        $appKey = (string) $request->query('app_key', config('iam.app_key'));

        if ($mode !== 'push') {
            Log::warning('iam.client.push_users_mode_mismatch', [
                'mode' => $mode,
                'expected' => 'push',
                'app_key' => $appKey,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'User push mode is disabled. Set iam.user_sync_mode to "push".',
                'hint' => 'Set IAM_USER_SYNC_MODE=push in client config and restart',
            ], 405);
        }

        $users = $request->input('users', []);

        Log::info('iam.client.push_users_received', [
            'count' => is_array($users) ? count($users) : null,
            'app_key' => $appKey,
        ]);

        if (! is_array($users)) {
            Log::warning('iam.client.push_users_invalid_payload', ['payload' => $request->all()]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid payload: users must be an array.',
            ], 400);
        }

        $userModelClass = config('iam.user_model', 'App\\Models\\User');
        if (! class_exists($userModelClass)) {
            return response()->json([
                'success' => false,
                'message' => 'Configured iam.user_model class does not exist: ' . $userModelClass,
            ], 500);
        }

        $userModel = new $userModelClass();
        $identifierField = config('iam.identifier_field', 'email');
        $allowCreate = config('iam.user_sync_from_iam_allow_create', true);
        $deleteMissing = config('iam.user_sync_from_iam_delete_missing', false);

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $idsToKeep = [];

        foreach ($users as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (! $this->userHasAccessToApplication($item, $appKey)) {
                $skipped++;
                Log::info('iam.client.user_skipped_no_app_access', [
                    'app_key' => $appKey,
                    'candidate' => [
                        'nip' => data_get($item, 'nip'),
                        'email' => data_get($item, 'email'),
                        'name' => data_get($item, 'name'),
                    ],
                ]);

                continue;
            }

            $identifierValue = data_get($item, $identifierField, null);
            $nip = data_get($item, 'nip');
            $email = data_get($item, 'email');

            if (empty($identifierValue) && empty($nip) && empty($email)) {
                $skipped++;
                continue;
            }

            $query = $userModelClass::query();
            $query->where(function ($q) use ($nip, $email, $identifierField, $identifierValue) {
                $hasCondition = false;

                if (! empty($nip)) {
                    $q->where('nip', $nip);
                    $hasCondition = true;
                }

                if (! empty($email)) {
                    if ($hasCondition) {
                        $q->orWhere('email', $email);
                    } else {
                        $q->where('email', $email);
                        $hasCondition = true;
                    }
                }

                if (! empty($identifierValue) && ! in_array($identifierField, ['nip', 'email'], true)) {
                    if ($hasCondition) {
                        $q->orWhere($identifierField, $identifierValue);
                    } else {
                        $q->where($identifierField, $identifierValue);
                    }
                }
            });

            $existingUser = $query->first();

            /**
             * Prepare data for user creation/update.
             * IMPORTANT: 'name' field must always have a value (NOT NULL constraint).
             * If IAM push data doesn't include 'name', use NIP as fallback.
             * 
             * This prevents "Field 'name' doesn't have a default value" errors
             * when syncing users from IAM in PUSH mode.
             */
            $name = data_get($item, 'name');
            if (empty($name)) {
                // Fallback chain: use NIP, email, or generic placeholder
                $name = $nip ?: ($email ?: 'Pengguna IAM');
            }

            $dataToSave = array_filter([
                'nip' => $nip ?? null,
                'email' => $email ?? null,
                'status' => $this->resolveStatusValue($item),
            ], function ($value) {
                return $value !== null;
            });

            // Always include 'name' with fallback value to avoid NOT NULL constraint violations
            $dataToSave['name'] = $name;

            if ($existingUser) {
                $userBefore = $existingUser->toArray();
                $existingUser->forceFill($dataToSave)->save();
                $updated++;
                $idsToKeep[] = $existingUser->getKey();

                Log::info('iam.client.user_updated', [
                    'user_id' => $existingUser->getKey(),
                    'before' => $userBefore,
                    'after' => $existingUser->toArray(),
                ]);
            } elseif ($allowCreate) {
                $dataToSave['password'] = $item['password'] ?? 'rschjaya1234';
                $newUser = new $userModelClass();
                $newUser->forceFill($dataToSave)->save();
                $created++;
                $idsToKeep[] = $newUser->getKey();
                $existingUser = $newUser;

                Log::info('iam.client.user_created', [
                    'user_id' => $newUser->getKey(),
                    'attributes' => $newUser->toArray(),
                ]);
            } else {
                $skipped++;
                Log::warning('iam.client.user_skipped', [
                    'reason' => 'create disabled',
                    'candidate' => $dataToSave,
                ]);
                continue;
            }

            if ($existingUser && method_exists($existingUser, 'syncRoles')) {
                $roles = array_filter(data_get($item, 'roles', []), fn($role) => is_string($role) || is_int($role));
                if (! empty($roles)) {
                    $existingUser->syncRoles($roles);
                }
            }

            $this->syncUnitKerja($existingUser, data_get($item, 'unit_kerja', []));
        }

        $deleted = 0;

        if ($deleteMissing) {
            $deleteQuery = $userModelClass::query()
                ->where(function ($q) {
                    $q->whereNotNull('nip')->orWhereNotNull('email');
                });

            if (! empty($idsToKeep)) {
                $deleteQuery->whereNotIn($userModel->getKeyName(), $idsToKeep);
            }

            $deleted = $deleteQuery->delete();

            Log::info('iam.client.push_users_deleted_missing', [
                'deleted_count' => $deleted,
                'ids_to_keep' => $idsToKeep,
            ]);
        }

        // Handle deleted user_unit_kerja relations if provided
        $deletedRelations = $request->input('deleted_user_unit_kerja', []);
        if (! empty($deletedRelations) && is_array($deletedRelations)) {
            $this->handleDeletedUserUnitKerjaRelations($deletedRelations, $userModelClass);
        }

        $result = [
            'success' => true,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'deleted' => $deleted,
            'allow_create' => $allowCreate,
            'delete_missing' => $deleteMissing,
        ];

        Log::info('iam.client.push_users_completed', array_merge($result, [
            'processed_user_ids' => $idsToKeep,
        ]));

        return response()->json($result);
    }

    protected function syncUnitKerja(Model $user, mixed $rawUnitKerja): void
    {
        if (! config('iam.sync_unit_kerja', true)) {
            return;
        }

        $unitKerjaItems = $this->normalizeUnitKerjaItems($rawUnitKerja);
        if (empty($unitKerjaItems)) {
            return;
        }

        if (! method_exists($user, 'unitKerjas')) {
            Log::warning('IAM unit_kerja push sync skipped because unitKerjas() relation is missing on user model.', [
                'user_id' => $user->getKey(),
            ]);
            return;
        }

        $unitKerjaModel = config('iam.unit_kerja_model', \App\Models\UnitKerja::class);

        if (! class_exists($unitKerjaModel)) {
            Log::warning('IAM unit_kerja push sync skipped because unit_kerja_model class does not exist.', [
                'unit_kerja_model' => $unitKerjaModel,
            ]);
            return;
        }

        $unitIds = [];
        foreach ($unitKerjaItems as $unitKerjaItem) {
            $unit = $this->resolveUnitKerjaRecord($unitKerjaModel, $unitKerjaItem, 'Synced from IAM push users');

            if ($unit) {
                $unitIds[] = $unit->getKey();
            }
        }

        if (! empty($unitIds)) {
            $user->unitKerjas()->sync(array_values(array_unique($unitIds)));

            Log::info('iam.client.user_unit_kerja_synced_from_push', [
                'user_id' => $user->getKey(),
                'unit_kerja' => $this->summarizeUnitKerjaItems($unitKerjaItems),
            ]);
        }
    }

    protected function userHasAccessToApplication(array $item, string $appKey): bool
    {
        $appKey = trim($appKey);

        if ($appKey === '') {
            return true;
        }

        $directAppKey = data_get($item, 'app_key');
        if (is_string($directAppKey) && $directAppKey !== '') {
            return $directAppKey === $appKey;
        }

        foreach (['applications', 'accessible_apps', 'app_keys', 'user_applications'] as $field) {
            $value = data_get($item, $field);

            if (is_string($value) && $value !== '') {
                if ($value === $appKey) {
                    return true;
                }

                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            foreach ($value as $application) {
                if (is_string($application) && $application === $appKey) {
                    return true;
                }

                if (is_array($application) && (string) data_get($application, 'app_key') === $appKey) {
                    return true;
                }
            }
        }

        $roles = data_get($item, 'roles', []);

        return is_array($roles) && ! empty($roles);
    }

    protected function normalizeUnitKerjaItems(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (is_string($raw)) {
            if (trim($raw) === '') {
                return [];
            }

            return array_values(array_filter(array_map(function ($item) {
                $item = trim($item);

                return $item === '' ? null : ['unit_name' => $item];
            }, explode(',', $raw))));
        }

        if (is_array($raw)) {
            if (array_is_list($raw)) {
                $items = $raw;
            } else {
                $items = [$raw];
            }

            $normalized = [];

            foreach ($items as $item) {
                $normalizedItem = $this->normalizeUnitKerjaItem($item);

                if ($normalizedItem !== null) {
                    $normalized[] = $normalizedItem;
                }
            }

            return $normalized;
        }

        $rawValue = trim((string) $raw);

        return $rawValue === '' ? [] : [['unit_name' => $rawValue]];
    }

    protected function normalizeUnitKerjaItem(mixed $item): ?array
    {
        if (is_string($item)) {
            $item = trim($item);

            return $item === '' ? null : ['unit_name' => $item];
        }

        if (is_numeric($item)) {
            return ['id' => (int) $item];
        }

        if (! is_array($item)) {
            return null;
        }

        $id = data_get($item, 'id');
        $slug = trim((string) data_get($item, 'slug', ''));
        $unitName = trim((string) data_get($item, 'unit_name', data_get($item, 'name', '')));
        $description = data_get($item, 'description');

        $normalized = [];

        if (is_numeric($id)) {
            $normalized['id'] = (int) $id;
        }

        if ($slug !== '') {
            $normalized['slug'] = $slug;
        }

        if ($unitName !== '') {
            $normalized['unit_name'] = $unitName;
        }

        if ($description !== null || array_key_exists('description', $item)) {
            $normalized['description'] = $description;
        }

        return empty($normalized) ? null : $normalized;
    }

    protected function resolveUnitKerjaRecord(string $unitKerjaModel, array $unitKerjaData, ?string $defaultDescription = null): ?object
    {
        $id = data_get($unitKerjaData, 'id');
        $slug = trim((string) data_get($unitKerjaData, 'slug', ''));
        $unitName = trim((string) data_get($unitKerjaData, 'unit_name', ''));
        $description = array_key_exists('description', $unitKerjaData)
            ? data_get($unitKerjaData, 'description')
            : $defaultDescription;

        $unit = null;

        if (is_numeric($id)) {
            $unit = $unitKerjaModel::withTrashed()->find($id);
        }

        if (! $unit && $slug !== '') {
            $unit = $unitKerjaModel::withTrashed()->firstOrNew(['slug' => $slug]);
        }

        if (! $unit && $unitName !== '') {
            $unit = $unitKerjaModel::withTrashed()->firstOrNew(['unit_name' => $unitName]);
        }

        if (! $unit) {
            return null;
        }

        $wasTrashed = method_exists($unit, 'trashed') && $unit->trashed();
        $shouldSave = ! $unit->exists || $wasTrashed;

        $attributes = [];

        if ($unitName !== '') {
            $attributes['unit_name'] = $unitName;
        } elseif (! $unit->exists && $slug !== '') {
            $attributes['unit_name'] = Str::of($slug)->replace(['-', '_'], ' ')->title()->toString();
        }

        if ($slug !== '') {
            $attributes['slug'] = $slug;
        }

        if ($description !== null || array_key_exists('description', $unitKerjaData)) {
            $attributes['description'] = $description;
        }

        if (! empty($attributes)) {
            $unit->fill($attributes);
            $shouldSave = true;
        }

        if ($wasTrashed) {
            $unit->restore();
            $shouldSave = true;
        }

        if ($shouldSave) {
            $unit->save();
        }

        return $unit;
    }

    protected function summarizeUnitKerjaItems(array $unitKerjaItems): array
    {
        return array_values(array_filter(array_map(function (array $item) {
            return $item['unit_name'] ?? $item['slug'] ?? ($item['id'] ?? null);
        }, $unitKerjaItems)));
    }

    protected function resolveStatusValue(array $item): ?string
    {
        if (array_key_exists('status', $item) && $item['status'] !== null) {
            return trim((string) $item['status']);
        }

        if (! array_key_exists('active', $item)) {
            return null;
        }

        $active = $item['active'];
        if (is_bool($active)) {
            return $active ? 'active' : 'inactive';
        }

        if (is_numeric($active)) {
            return intval($active) === 1 ? 'active' : 'inactive';
        }

        $normalized = strtolower(trim((string) $active));
        if (in_array($normalized, ['1', 'true', 'yes', 'active'], true)) {
            return 'active';
        }

        return 'inactive';
    }

    /**
     * Handle deletion of user_unit_kerja relations.
     * Detaches users from unit kerja that were removed on IAM side.
     */
    protected function handleDeletedUserUnitKerjaRelations(array $deletedRelations, string $userModelClass): void
    {
        if (empty($deletedRelations)) {
            return;
        }

        $unitKerjaModel = config('iam.unit_kerja_model', \App\Models\UnitKerja::class);

        foreach ($deletedRelations as $relation) {
            $userId = null;
            $unitId = null;

            // Resolve user ID
            if (! empty($relation['user_nip'])) {
                $user = $userModelClass::where('nip', $relation['user_nip'])->first();
                $userId = $user?->id;
            } elseif (! empty($relation['user_email'])) {
                $user = $userModelClass::where('email', $relation['user_email'])->first();
                $userId = $user?->id;
            } elseif (! empty($relation['user_id'])) {
                $userId = $relation['user_id'];
            }

            // Resolve unit ID
            if (! empty($relation['unit_slug'])) {
                $unit = $unitKerjaModel::withTrashed()->where('slug', $relation['unit_slug'])->first();
                $unitId = $unit?->id;
            } elseif (! empty($relation['unit_kerja_id'])) {
                $unitId = $relation['unit_kerja_id'];
            }

            // Detach the user from unit kerja
            if ($userId && $unitId) {
                $user = $userModelClass::find($userId);
                if ($user && method_exists($user, 'unitKerjas')) {
                    $user->unitKerjas()->detach($unitId);

                    Log::info('iam.client.user_unit_kerja_detached_from_push', [
                        'user_id' => $userId,
                        'unit_kerja_id' => $unitId,
                    ]);
                }
            }
        }
    }
}
