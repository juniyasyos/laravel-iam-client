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
        $users = $this->resolveUsers($payload);
        $userUnitRelations = $this->resolveRelations($payload);

        $unitModelClass = Config::get('iam.unit_kerja_model', UnitKerja::class);
        $userModelClass = Config::get('iam.user_model', \App\Models\User::class);

        $unitRecordsBySlug = [];
        $syncedUnits = 0;

        foreach ($units as $item) {
            if (! isset($item['slug']) || Str::of($item['slug'])->trim()->isEmpty()) {
                continue;
            }

            $unit = $unitModelClass::withTrashed()
                ->where('slug', $item['slug'])
                ->first();

            if ($unit) {
                if (method_exists($unit, 'trashed') && $unit->trashed()) {
                    $unit->restore();
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

        return [
            'success' => true,
            'message' => 'Sinkronisasi Unit Kerja berhasil.',
            'synced_units' => $syncedUnits,
            'synced_users' => $syncedUsers,
            'synced_relations' => $syncedRelations,
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
}
