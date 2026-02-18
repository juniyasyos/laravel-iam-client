<?php

namespace Juniyasyos\IamClient\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BackchannelLogoutController extends Controller
{
    /**
     * Handle OP → client back‑channel logout (server→server).
     * Public endpoint; signature verification is done by middleware.
     */
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'event' => 'required|string|in:logout',
            'user.id' => 'required',
        ]);

        $userId = data_get($data, 'user.id');

        // Best‑effort: delete DB sessions when using "database" driver
        try {
            if (config('session.driver') === 'database') {
                $deleted = DB::table('sessions')
                    ->where('payload', 'like', '%"user_id";i:' . $userId . '%')
                    ->delete();

                Log::info('iam.client.backchannel_session_cleanup', [
                    'user_id' => $userId,
                    'deleted_sessions' => $deleted,
                    'request_id' => $request->header('X-IAM-Request-Id'),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('iam.client.backchannel_session_cleanup_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }

        // Best‑effort: revoke tokens on local user (supports Sanctum/Passport via tokens() relation)
        $userModel = config('iam.user_model', 'App\\Models\\User');
        $revokedTokens = null;

        if (class_exists($userModel)) {
            try {
                $user = $userModel::find($userId) ?: $userModel::where('iam_id', $userId)->first();

                if ($user && method_exists($user, 'tokens')) {
                    $revokedTokens = $user->tokens()->delete();

                    Log::info('iam.client.backchannel_revoke', [
                        'user_id' => $userId,
                        'revoked_tokens' => $revokedTokens,
                        'request_id' => $request->header('X-IAM-Request-Id'),
                    ]);
                } else {
                    Log::info('iam.client.backchannel_revoke_skipped', ['user_id' => $userId]);
                }
            } catch (\Throwable $e) {
                Log::warning('iam.client.backchannel_revoke_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            }
        }

        Log::info('iam.client.backchannel_logout_processed', [
            'user_id' => $userId,
            'revoked_tokens' => $revokedTokens,
            'request_id' => $request->header('X-IAM-Request-Id'),
        ]);

        return response()->json(['ok' => true]);
    }
}
