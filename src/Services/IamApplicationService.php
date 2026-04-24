<?php

namespace Juniyasyos\IamClient\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Juniyasyos\IamClient\Support\IamConfig;

class IamApplicationService
{
    /**
     * Fetch accessible applications directly from IAM server.
     *
     * @return array|null
     */
    public function getAccessibleApplications(): ?array
    {
        if (!config('iam.enabled')) {
            Log::debug('[IamApplicationService] IAM not enabled');
            return null;
        }

        $token = session('iam.access_token') ?? session('iam.access_token_backup');
        if (empty($token)) {
            Log::debug('[IamApplicationService] No IAM access token in session');
            return null;
        }

        $endpoint = IamConfig::userApplicationsEndpoint();

        Log::info('[IamApplicationService] Fetching IAM applications', [
            'user_id' => Auth::id(),
            'endpoint' => $endpoint,
            'has_token' => true,
        ]);

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(10)
            ->get($endpoint);

        if (!$response->successful()) {
            Log::warning('[IamApplicationService] Failed to fetch applications from IAM server', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 1000),
            ]);
            return null;
        }

        $payload = $response->json();

        Log::info('[IamApplicationService] Successfully fetched applications from IAM server', [
            'count' => count($payload['applications'] ?? []),
        ]);

        return $payload;
    }

    /**
     * Get formatted applications list for display.
     *
     * @return array
     */
    public function getFormattedApplications(): array
    {
        $data = $this->getAccessibleApplications();

        if (!$data || empty($data['applications'])) {
            return [];
        }

        return collect($data['applications'])
            ->map(fn($app) => [
                'id' => $app['id'] ?? null,
                'app_key' => $app['app_key'] ?? null,
                'name' => $app['name'] ?? null,
                'app_url' => $app['app_url'] ?? $app['urls']['primary'] ?? null,
                'logo_url' => $app['logo_url'] ?? null,
                'enabled' => (bool) ($app['enabled'] ?? true),
            ])
            ->filter(fn($app) => $app['enabled'] && $app['app_url'])
            ->values()
            ->toArray();
    }

    /**
     * Get application by app key.
     *
     * @param string|null $appKey
     * @return array|null
     */
    public function getApplicationByKey(?string $appKey = null): ?array
    {
        if (empty($appKey)) {
            return null;
        }

        $apps = $this->getAccessibleApplications();

        if (!isset($apps['applications']) || !is_array($apps['applications'])) {
            return null;
        }

        foreach ($apps['applications'] as $app) {
            if (isset($app['app_key']) && $app['app_key'] === $appKey) {
                return $app;
            }
        }

        return null;
    }
}
