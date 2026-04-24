<?php

namespace Juniyasyos\IamClient\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class IamTokenManager
{
    /**
     * Get valid access token, refresh if needed.
     *
     * @return string|null
     */
    public function getValidToken(): ?string
    {
        $token = session('iam.access_token');

        if (!empty($token)) {
            Log::debug('[IamTokenManager] Session token available', [
                'has_token' => true,
                'token_length' => strlen($token),
            ]);

            return $token;
        }

        $backupToken = session('iam.access_token_backup');
        if (!empty($backupToken)) {
            Log::info('[IamTokenManager] Using backup session token', [
                'user_id' => Auth::id(),
                'token_length' => strlen($backupToken),
            ]);

            return $backupToken;
        }

        Log::warning('[IamTokenManager] Token tidak ada di session', [
            'user_id' => Auth::id(),
        ]);

        return null;
    }

    /**
     * Refresh token using the current bearer token.
     *
     * @param string $token
     * @return string|null
     */
    public function refreshUsingToken(string $token): ?string
    {
        Log::info('[IamTokenManager] Refresh skipped: JWT SSO-only mode', [
            'user_id' => Auth::id(),
        ]);

        return null;
    }

    /**
     * Refresh JWT token using refresh_token.
     *
     * @param string $refreshToken
     * @return string|null
     */
    public function refreshToken(string $refreshToken): ?string
    {
        Log::info('[IamTokenManager] Refresh token flow disabled in JWT SSO-only mode', [
            'user_id' => Auth::id(),
        ]);

        return null;
    }

    /**
     * Check whether a token exists in session.
     *
     * @return bool
     */
    public function hasValidToken(): bool
    {
        return !empty(session('iam.access_token'));
    }

    /**
     * Clear IAM tokens from the session.
     *
     * @return void
     */
    public function clearTokens(): void
    {
        Session::forget(['iam.access_token', 'iam.refresh_token']);

        Log::info('[IamTokenManager] Tokens cleared from session', [
            'user_id' => Auth::id(),
        ]);
    }

    /**
     * Get debug information about IAM token session state.
     *
     * @return array
     */
    public function getDebugInfo(): array
    {
        $token = session('iam.access_token');
        $refreshToken = session('iam.refresh_token');

        return [
            'has_access_token' => !empty($token),
            'access_token_length' => $token ? strlen($token) : 0,
            'has_refresh_token' => !empty($refreshToken),
            'refresh_token_length' => $refreshToken ? strlen($refreshToken) : 0,
            'session_iam_data' => session('iam', []),
        ];
    }
}
