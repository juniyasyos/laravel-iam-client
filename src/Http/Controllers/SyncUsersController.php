<?php

namespace Juniyasyos\IamClient\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Juniyasyos\IamClient\Support\IamConfig;

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

        // note: the `app_key` query parameter is intentionally ignored by
        // default; the server no longer needs to supply it (see configuration
        // in the IAM server).  if you still require it for compatibility you
        // can inspect `$request->query('app_key')` here.
        $appKey = $request->query('app_key');

        $userModel = config('iam.user_model', \App\Models\User::class);
        $fields = config('iam.user_fields', []);

        $users = $userModel::query()
            ->get()
            ->map(function ($user) use ($fields) {
                $item = [];

                foreach ($fields as $column => $claim) {
                    if (isset($user->{$column})) {
                        $item[$column] = $user->{$column};
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
            'app_key' => $appKey,
            'users' => $users,
            'total' => count($users),
        ]);
    }
}
