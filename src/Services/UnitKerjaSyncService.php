<?php

namespace Juniyasyos\IamClient\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Juniyasyos\IamClient\Models\UnitKerja;

class UnitKerjaSyncService
{
    public function sync(array $payload): array
    {
        $units = $this->resolveUnits($payload);
        $deletedUnits = $this->resolveDeletedUnits($payload);
        $users = $this->resolveUsers($payload);
        $userUnitRelations = $this->resolveRelations($payload);
        $deletedRelations = $this->resolveDeletedRelations($payload);

        $unitModelClass = Config::get('iam.unit_kerja_model', UnitKerja::class);
        $userModelClass = Config::get('iam.user_model', \App\Models\User::class);
        $deleteSoft = Config::get('iam.unit_kerja.delete_soft', true);

        $unitRecordsBySlug = [];
        $syncedUnits = 0;

        // Upsert active units
        foreach ($units as $item) {
            if (! isset($item['slug']) || Str::of($item['slug'])->trim()->isEmpty()) {
                continue;
            }

            $unit = $this->queryWithTrashed($unitModelClass)
                ->where('slug', $item['slug'])
                ->first();

            if ($unit) {
                if ($this->isTrashed($unit)) {
                    $this->restoreModel($unit);
                }

                $unit->fill([
                    'unit_name' => $item['unit_name'] ?? null,
                    'description' => $item['description'] ?? null,
                ]);
                $unit->save();
            } else {
                $unit = $unitModelClass::create([
                    'slug' => $item['slug'],
                    'unit_name' => $item['unit_name'] ?? null,
                    'description' => $item['description'] ?? null,
                ]);
            }

            $unitRecordsBySlug[$unit->slug] = $unit;
            $syncedUnits++;
        }

        // Handle deleted units from IAM center
        $deletedUnitsCount = $this->handleDeletedUnits($deletedUnits, $unitModelClass, $deleteSoft);

        $userIndexByNip = [];
        $userIndexByEmail = [];
        $syncedUsers = 0;

        foreach ($users as $item) {
            if (empty($item['nip']) && empty($item['email'])) {
                continue;
            }

            $query = $userModelClass::query();
            $hasCondition = false;

            if (! empty($item['nip'])) {
                $query->where('nip', $item['nip']);
                $hasCondition = true;
            }

            if (! empty($item['email'])) {
                if ($hasCondition) {
                    $query->orWhere('email', $item['email']);
                } else {
                    $query->where('email', $item['email']);
                    $hasCondition = true;
                }
            }

            $existingUser = $query->first();

            $data = array_filter([
                'name' => $item['name'] ?? null,
                'nip' => $item['nip'] ?? null,
                'status' => $item['status'] ?? null,
                'iam_id' => $item['iam_id'] ?? null,
                'email' => $item['email'] ?? null,
            ], fn($value) => $value !== null);

            if ($existingUser) {
                $existingUser->update($data);
                $user = $existingUser;
            } else {
                $data['password'] = $item['password'] ?? 'Rschjaya1234';
                $user = $userModelClass::create($data);
            }

            if (! empty($item['nip'])) {
                $userIndexByNip[$item['nip']] = $user->id;
            }
            if (! empty($item['email'])) {
                $userIndexByEmail[$item['email']] = $user->id;
            }

            $syncedUsers++;
        }

        $relationsByUnitSlug = [];

        foreach ($userUnitRelations as $relation) {
            $unit = null;
            $user = null;

            if (! empty($relation['unit_slug'])) {
                $unit = $unitModelClass::where('slug', $relation['unit_slug'])->first();
            }

            if (! $unit && ! empty($relation['unit_kerja_id'])) {
                $unit = $unitModelClass::find($relation['unit_kerja_id']);
            }

            if (! $user && ! empty($relation['user_nip']) && ! empty($userIndexByNip[$relation['user_nip']])) {
                $user = $userModelClass::find($userIndexByNip[$relation['user_nip']]);
            }

            if (! $user && ! empty($relation['user_email']) && ! empty($userIndexByEmail[$relation['user_email']])) {
                $user = $userModelClass::find($userIndexByEmail[$relation['user_email']]);
            }

            if (! $user && ! empty($relation['user_id'])) {
                $user = $userModelClass::find($relation['user_id']);
            }

            if ($unit && $user) {
                $relationsByUnitSlug[$unit->slug][] = $user->id;
            }
        }

        $syncedRelations = 0;

        foreach ($unitRecordsBySlug as $slug => $unit) {
            if (! method_exists($unit, 'users')) {
                continue;
            }

            $syncIds = array_values(array_unique($relationsByUnitSlug[$slug] ?? []));
            $unit->users()->sync($syncIds);
            $syncedRelations += count($syncIds);
        }

        // Handle deleted relations (force delete pivot rows always)
        $deletedRelationsCount = $this->handleDeletedRelations($deletedRelations, $unitModelClass, $userModelClass);

        return [
            'success' => true,
            'message' => 'Sinkronisasi Unit Kerja berhasil.',
            'synced_units' => $syncedUnits,
            'deleted_units' => $deletedUnitsCount,
            'synced_users' => $syncedUsers,
            'synced_relations' => $syncedRelations,
            'deleted_relations' => $deletedRelationsCount,
        ];
    }

    protected function resolveUnits(array $payload): array
    {
        $units = data_get($payload, 'data.units', null);

        if ($units === null) {
            $units = $payload['units'] ?? [];
        }

        return is_array($units) ? $units : [];
    }

    protected function resolveUsers(array $payload): array
    {
        $users = data_get($payload, 'data.users', null);

        if ($users === null) {
            $users = data_get($payload, 'users', []);
        }

        return is_array($users) ? $users : [];
    }

    protected function resolveRelations(array $payload): array
    {
        $relations = data_get($payload, 'data.user_unit_kerja', null);

        if ($relations === null) {
            $relations = data_get($payload, 'data.userUnitKerja', null);
        }

        if ($relations === null) {
            $relations = data_get($payload, 'user_unit_kerja', null);
        }

        if ($relations === null) {
            $relations = data_get($payload, 'userUnitKerja', []);
        }

        return is_array($relations) ? $relations : [];
    }

    protected function resolveDeletedUnits(array $payload): array
    {
        $deletedUnits = data_get($payload, 'data.deleted_units', null);

        if ($deletedUnits === null) {
            $deletedUnits = data_get($payload, 'deleted_units', []);
        }

        return is_array($deletedUnits) ? $deletedUnits : [];
    }

    protected function resolveDeletedRelations(array $payload): array
    {
        $deletedRelations = data_get($payload, 'data.deleted_user_unit_kerja', null);

        if ($deletedRelations === null) {
            $deletedRelations = data_get($payload, 'deleted_user_unit_kerja', []);
        }

        return is_array($deletedRelations) ? $deletedRelations : [];
    }

    protected function handleDeletedUnits(array $deletedUnits, string $unitModelClass, bool $deleteSoft): int
    {
        if (empty($deletedUnits)) {
            return 0;
        }

        $count = 0;

        foreach ($deletedUnits as $item) {
            if (! isset($item['slug']) || Str::of($item['slug'])->trim()->isEmpty()) {
                continue;
            }

            $unit = $this->queryWithTrashed($unitModelClass)
                ->where('slug', $item['slug'])
                ->first();

            if ($unit) {
                if ($deleteSoft) {
                    // Soft delete: mark as deleted but keep data
                    if (! $this->isTrashed($unit)) {
                        $this->deleteModel($unit);
                    }
                } else {
                    // Force delete: permanently remove
                    $this->forceDeleteModel($unit);
                }

                $count++;
            }
        }

        return $count;
    }

    protected function handleDeletedRelations(array $deletedRelations, string $unitModelClass, string $userModelClass): int
    {
        if (empty($deletedRelations)) {
            return 0;
        }

        $count = 0;

        foreach ($deletedRelations as $relation) {
            $unitId = null;
            $userId = null;

            // Resolve unit ID from slug or direct ID
            if (! empty($relation['unit_slug'])) {
                $unit = $this->queryWithTrashed($unitModelClass)->where('slug', $relation['unit_slug'])->first();
                $unitId = $unit?->id;
            } elseif (! empty($relation['unit_kerja_id'])) {
                $unitId = $relation['unit_kerja_id'];
            }

            // Resolve user ID from nip/email or direct ID
            if (! empty($relation['user_nip'])) {
                $user = $userModelClass::where('nip', $relation['user_nip'])->first();
                $userId = $user?->id;
            } elseif (! empty($relation['user_email'])) {
                $user = $userModelClass::where('email', $relation['user_email'])->first();
                $userId = $user?->id;
            } elseif (! empty($relation['user_id'])) {
                $userId = $relation['user_id'];
            }

            // Force delete pivot row always (no soft delete on pivot)
            if ($unitId && $userId) {
                \Illuminate\Support\Facades\DB::table('user_unit_kerja')
                    ->where('user_id', $userId)
                    ->where('unit_kerja_id', $unitId)
                    ->delete();

                $count++;
            }
        }

        return $count;
    }

    /**
     * Safely query a model with or without SoftDeletes support.
     * Returns query builder that includes trashed records if model supports it.
     */
    protected function queryWithTrashed(string $modelClass): \Illuminate\Database\Eloquent\Builder
    {
        $query = $modelClass::query();

        // Only call withTrashed if model uses SoftDeletes
        if (method_exists($query, 'withTrashed')) {
            return $query->withTrashed();
        }

        return $query;
    }

    /**
     * Check if a model instance is trashed (soft deleted).
     * Safe for models without SoftDeletes.
     */
    protected function isTrashed($model): bool
    {
        return method_exists($model, 'trashed') && $model->trashed();
    }

    /**
     * Restore a model if it supports SoftDeletes.
     */
    protected function restoreModel($model): bool
    {
        if (method_exists($model, 'restore')) {
            $model->restore();
            return true;
        }

        return false;
    }

    /**
     * Soft delete a model.
     * For models without SoftDeletes, this is a hard delete.
     */
    protected function deleteModel($model): bool
    {
        if (method_exists($model, 'delete')) {
            $model->delete();
            return true;
        }

        return false;
    }

    /**
     * Force delete a model.
     * If model supports SoftDeletes, uses forceDelete().
     * Otherwise uses delete() (which is already hard delete).
     */
    protected function forceDeleteModel($model): bool
    {
        if (method_exists($model, 'forceDelete')) {
            $model->forceDelete();
            return true;
        } elseif (method_exists($model, 'delete')) {
            $model->delete();
            return true;
        }

        return false;
    }
}
