<?php

namespace Juniyasyos\IamClient\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Juniyasyos\IamClient\Support\IamConfig;
use Illuminate\Support\Facades\Cache;

class SyncUsersController extends Controller
{
    /**
     * Return a list of application users for IAM to consume during sync.
     *
     * The output mimics the structure expected by the IAM server's
     * `ApplicationUserSyncService` (see `laravel-iam/app/Domain/Iam/Services/ApplicationUserSyncService.php`).
     *
     * This controller is deliberately lightweight, relying on the host
     * application to configure the user model and field mapping via
     * `config('iam.user_model')` and `config('iam.user_fields')`.  Roles are
     * included if the user model implements Spatie's role helpers.
     *
     * The route that exposes this action should be protected with the
     * `iam.backchannel.verify` middleware so that only a signed request from
     * the IAM server is accepted.
     */
    public function __invoke(Request $request): JsonResponse
    {
        if (! IamConfig::syncUsersEnabled()) {
            // feature disabled, behave as if endpoint does not exist
            abort(404);
        }

        // the IAM server passes `app_key` purely for identification; the
        // controller used to ignore it, but returning it in the payload makes
        // debugging much easier (and the server is free to reject mismatches).
        $appKey = $request->query('app_key');

        // optionally you can validate here if you want to harden the endpoint:
        // if ($appKey !== config('iam.app_key')) {
        //     abort(403, 'Invalid application key');
        // }

        $userModel = config('iam.user_model', \App\Models\User::class);
        $fields = config('iam.user_fields', []);

        if (! array_key_exists('email', $fields)) {
            // If email is not part of sync mapping the IAM server will get
            // null for email on newly created users.
            Log::warning('iam.sync_users_email_field_missing', [
                'configured_fields' => array_keys($fields),
            ]);
        }

        // Prepare query and eager-load roles when available to avoid N+1
        $userModelInstance = new $userModel();
        $query = $userModel::query();
        if (method_exists($userModelInstance, 'roles')) {
            $query = $query->with('roles');
        }

        $users = $query->get()->map(function ($user) use ($fields) {
                $item = [];

                foreach ($fields as $column => $claim) {
                    // Always include configured fields (even when null) to avoid
                    // losing values during hourly sync comparisons.
                    $item[$column] = $user->{$column} ?? null;
                }

                // Do a forced baseline for core fields.
                foreach (['nip', 'email', 'name', 'status'] as $core) {
                    if (! isset($item[$core])) {
                        $item[$core] = $user->{$core} ?? null;
                    }
                }


                // include roles when available; output as simple array of slugs/names
                if (method_exists($user, 'getRoleNames')) {
                    $item['roles'] = $user->getRoleNames()->toArray();
                }

                return $item;
            })
            ->values()
            ->toArray();

        return response()->json([
            // echo the app_key back so the caller can verify the origin of the
            // data and log it.  IAM will compare this value against the one it
            // requested.
            'app_key' => $appKey,
            'users' => $users,
            'total' => count($users),
        ]);
    }
}
