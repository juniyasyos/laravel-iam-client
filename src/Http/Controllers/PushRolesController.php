<?php

namespace Juniyasyos\IamClient\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Spatie\Permission\Models\Role;

class PushRolesController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $mode = config('iam.role_sync_mode', 'pull');

        if ($mode !== 'push') {
            return response()->json([
                'success' => false,
                'message' => 'Role push mode is disabled. Set iam.role_sync_mode to "push".',
            ], 405);
        }

        $roles = $request->input('roles', []);

        if (!is_array($roles)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid payload: roles must be an array.',
            ], 400);
        }

        $allowCreate = config('iam.role_sync_from_iam_allow_create', false);

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($roles as $roleData) {
            if (! is_array($roleData) || empty($roleData['slug'])) {
                continue;
            }

            $slug = (string) $roleData['slug'];
            $name = $roleData['name'] ?? $slug;
            $description = $roleData['description'] ?? null;
            $isSystem = $roleData['is_system'] ?? false;

            $role = Role::where('name', $slug)->first();

            if ($role) {
                $role->update([
                    'name' => $slug,
                    'guard_name' => $role->guard_name ?? config('auth.defaults.guard', 'web'),
                ]);

                // store description if available
                if (property_exists($role, 'description')) {
                    $role->description = $description;
                    $role->save();
                }

                $updated++;
                continue;
            }

            if (! $allowCreate) {
                $skipped++;
                continue;
            }

            $role = Role::create([
                'name' => $slug,
                'guard_name' => config('auth.defaults.guard', 'web'),
            ]);

            if ($role && property_exists($role, 'description')) {
                $role->description = $description;
                $role->save();
            }

            $created++;
        }

        return response()->json([
            'success' => true,
            'mode' => $mode,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'allow_create' => $allowCreate,
        ]);
    }
}
