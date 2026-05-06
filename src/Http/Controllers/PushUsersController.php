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

        if ($mode !== 'push') {
            Log::warning('iam.client.push_users_mode_mismatch', [
                'mode' => $mode,
                'expected' => 'push',
                'app_key' => $request->query('app_key'),
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
            'app_key' => $request->query('app_key'),
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
                $existingUser->update($dataToSave);
                $updated++;
                $idsToKeep[] = $existingUser->getKey();

                Log::info('iam.client.user_updated', [
                    'user_id' => $existingUser->getKey(),
                    'before' => $userBefore,
                    'after' => $existingUser->toArray(),
                ]);
            } elseif ($allowCreate) {
                $dataToSave['password'] = $item['password'] ?? 'rschjaya1234';
                $newUser = $userModelClass::create($dataToSave);
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

        $unitKerjaValues = $this->normalizeUnitKerjaValues($rawUnitKerja);
        if (empty($unitKerjaValues)) {
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
        foreach ($unitKerjaValues as $unitValue) {
            if (is_numeric($unitValue) && $unitKerjaModel::whereKey($unitValue)->exists()) {
                $unit = $unitKerjaModel::find($unitValue);
            } else {
                $unit = $unitKerjaModel::firstOrCreate(
                    ['unit_name' => (string) $unitValue],
                    ['description' => 'Synced from IAM push users']
                );
            }

            if ($unit) {
                $unitIds[] = $unit->getKey();
            }
        }

        if (! empty($unitIds)) {
            $user->unitKerjas()->sync(array_values(array_unique($unitIds)));

            Log::info('iam.client.user_unit_kerja_synced_from_push', [
                'user_id' => $user->getKey(),
                'unit_kerja' => $unitKerjaValues,
            ]);
        }
    }

    protected function normalizeUnitKerjaValues(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (is_string($raw)) {
            if (trim($raw) === '') {
                return [];
            }

            return array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)), fn($item) => $item !== '')));
        }

        if (is_array($raw)) {
            $values = array_filter(array_map(function ($item) {
                if (is_string($item)) {
                    return trim($item);
                }

                return $item;
            }, $raw), fn($item) => $item !== null && $item !== '');

            return array_values(array_unique($values));
        }

        return [(string) $raw];
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
}
