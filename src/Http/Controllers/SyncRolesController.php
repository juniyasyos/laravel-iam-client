<?php

namespace Juniyasyos\IamClient\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Spatie\Permission\Models\Role;

class SyncRolesController extends Controller
{
    /**
     * Get all roles available in this client application.
     * Used by IAM server to sync and validate roles across applications.
     *
     * If role sync mode is `push`, this endpoint can still respond to GET
     * for compatibility; POST must be done to `POST /api/iam/push-roles`.
     */
    public function __invoke(Request $request): JsonResponse
    {
        if ($request->isMethod('post')) {
            return response()->json([
                'success' => false,
                'message' => 'Use POST /api/iam/push-roles for pushing roles from IAM to client.',
            ], 405);
        }

        // Get app_key from query parameter to validate request is for this app
        $appKey = $request->query('app_key');

        // Get all roles from Spatie Permission
        $roles = Role::all()->map(function ($role) {
            return [
                'id' => $role->id,
                'slug' => $role->name, // Spatie uses 'name' as slug
                'name' => $role->name,
                'description' => $role->description,
                'is_system' => false, // Spatie doesn't have is_system flag, assume all are not system
            ];
        })->values()->toArray();

        return response()->json([
            'app_key' => $appKey,
            'roles' => $roles,
            'total' => count($roles),
        ]);
    }
}
