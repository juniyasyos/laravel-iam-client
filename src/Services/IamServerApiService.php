<?php

namespace Juniyasyos\IamClient\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Juniyasyos\IamClient\Support\IamConfig;

class IamServerApiService
{
    public function getUserApplications(): array
    {
        $token = session('iam.access_token') ?: session('iam.access_token_backup');

        if (empty($token)) {
            Log::warning('IamServerApiService: no IAM access token in session', [
                'session_iam' => session('iam'),
                'session_iam_payload' => session('iam.payload'),
                'session_id' => session()->getId(),
                'endpoint' => IamConfig::userApplicationsEndpoint(),
                'request_url' => request()->fullUrl(),
                'request_path' => request()->path(),
            ]);

            // Fallback to local authenticated user data if available
            if (Auth::check() && method_exists(Auth::user(), 'accessibleApps')) {
                return [
                    'source' => 'local-fallback',
                    'applications' => Auth::user()->accessibleApps(),
                ];
            }

            // Another fallback possibility for old integrations: check SSO session payload.
            $iamData = session('iam', []);
            if (! empty($iamData) && is_array($iamData) && Arr::get($iamData, 'sub')) {
                return [
                    'source' => 'iam-session-fallback',
                    'applications' => [],
                    'hint' => 'Token missing but IAM session exists; consider re-login or cache app list',
                ];
            }

            return [
                'error' => 'iam_access_token_missing',
                'message' => 'IAM access token not available in session. Please login via SSO.',
            ];
        }

        $endpoint = IamConfig::userApplicationsEndpoint();

        $response = Http::timeout(10)
            ->withToken($token)
            ->acceptJson()
            ->get($endpoint);

        if (! $response->successful()) {
            Log::error('IamServerApiService failed to load user applications from IAM', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];
        }

        return $response->json() ?? [];
    }
}
