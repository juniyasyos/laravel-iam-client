<?php

namespace Juniyasyos\IamClient\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Juniyasyos\IamClient\Support\IamConfig;

/**
 * Service for accessing user applications from IAM server.
 * 
 * Provides methods to fetch user's accessible applications with metadata:
 * - Basic applications list with roles
 * - Detailed applications with logos, URLs, and profile information
 * 
 * Usage:
 *   $service = app(UserApplicationsService::class);
 *   $apps = $service->getApplications();
 *   $detailedApps = $service->getApplicationsDetail();
 */
class UserApplicationsService
{
    /**
     * Get user's accessible applications with metadata.
     * 
     * Returns list of applications the user has access to, including:
     * - id, app_key, name, description
     * - status (active/inactive)
     * - logo_url - Application logo URL
     * - app_url - Primary application URL
     * - redirect_uris - All redirect URIs
     * - callback_url, backchannel_url
     * - roles - List of roles in each app
     * - roles_count - Number of roles
     * 
     * @return array
     * 
     * @example
     *   $apps = app(UserApplicationsService::class)->getApplications();
     *   // Returns:
     *   // [
     *   //     'source' => 'iam-server',
     *   //     'sub' => '1',
     *   //     'user_id' => 1,
     *   //     'total_accessible_apps' => 2,
     *   //     'applications' => [
     *   //         [
     *   //             'id' => 1,
     *   //             'app_key' => 'siimut',
     *   //             'name' => 'SIIMUT',
     *   //             'logo_url' => 'https://...',
     *   //             'app_url' => 'http://127.0.0.1:8088',
     *   //             'status' => 'active',
     *   //             ...
     *   //         ]
     *   //     ]
     *   // ]
     */
    public function getApplications(): array
    {
        return $this->fetchFromIam('/users/applications', 'applications');
    }

    /**
     * Get detailed user applications with complete metadata.
     * 
     * Returns comprehensive application information including:
     * - All metadata from basic endpoint
     * - Logo availability status
     * - All URL types (primary, redirects, callback, backchannel)
     * - Timestamps (created_at, updated_at)
     * - Access profiles that provide access to each app
     * - User's access profiles list
     * 
     * @return array
     * 
     * @example
     *   $appsDetail = app(UserApplicationsService::class)->getApplicationsDetail();
     *   // Returns:
     *   // [
     *   //     'source' => 'iam-server',
     *   //     'sub' => '1',
     *   //     'user_id' => 1,
     *   //     'total_apps' => 2,
     *   //     'applications' => [
     *   //         [
     *   //             'id' => 1,
     *   //             'app_key' => 'siimut',
     *   //             'name' => 'SIIMUT',
     *   //             'metadata' => [
     *   //                 'logo' => ['url' => '...', 'available' => false],
     *   //                 'urls' => [...],
     *   //                 'created_at' => '2026-04-01T...',
     *   //             ],
     *   //             'access_profiles_using_this_app' => [
     *   //                 ['id' => 1, 'name' => 'Super Admin', 'slug' => 'super-admin']
     *   //             ],
     *   //             ...
     *   //         ]
     *   //     ],
     *   //     'user_profiles' => [...]
     *   // ]
     */
    public function getApplicationsDetail(): array
    {
        $endpoint = IamConfig::userApplicationsDetailEndpoint();

        return $this->fetchFromIam($endpoint, 'applicationsDetail');
    }

    /**
     * Get applications prepared for UI switcher components.
     *
     * @return array
     */
    public function getApplicationsForSwitcher(): array
    {
        $detail = $this->getApplicationsDetail();

        if (!is_array($detail) || !isset($detail['applications']) || !is_array($detail['applications'])) {
            return [];
        }

        return $this->transformApplicationsForSwitcher($detail['applications']);
    }

    /**
     * Transform raw API response to a display-ready switcher payload.
     *
     * @param array $apps
     * @return array
     */
    private function transformApplicationsForSwitcher(array $apps): array
    {
        return collect($apps)
            ->filter(fn($app) => ($app['status'] ?? 'active') === 'active')
            ->map(fn($app) => [
                'id' => $app['id'] ?? null,
                'app_key' => $app['app_key'] ?? null,
                'name' => $app['name'] ?? 'Unknown Application',
                'description' => $app['description'] ?? null,
                'enabled' => ($app['status'] ?? 'active') === 'active',
                'logo_url' => $app['metadata']['logo']['url'] ?? $app['logo_url'] ?? null,
                'has_logo' => $app['metadata']['logo']['available'] ?? !empty($app['logo_url']),
                'app_url' => $app['metadata']['urls']['primary'] ?? $app['app_url'] ?? null,
                'redirect_uris' => $app['metadata']['urls']['all_redirects'] ?? $app['redirect_uris'] ?? [],
                'status' => $app['status'] ?? 'active',
                'roles_count' => $app['roles_count'] ?? count($app['roles'] ?? []),
                'roles' => collect($app['roles'] ?? [])
                    ->map(fn($role) => [
                        'id' => $role['id'] ?? null,
                        'slug' => $role['slug'] ?? null,
                        'name' => $role['name'] ?? 'User',
                        'description' => $role['description'] ?? null,
                        'is_system' => $role['is_system'] ?? false,
                    ])
                    ->toArray(),
                'urls' => $app['metadata']['urls'] ?? $app['urls'] ?? [
                    'primary' => $app['app_url'] ?? null,
                    'all_redirects' => $app['redirect_uris'] ?? [],
                ],
            ])
            ->filter(fn($app) => !empty($app['app_url']))
            ->values()
            ->toArray();
    }

    /**
     * Get applications for a specific app (filter if app_key exists).
     * 
     * @param string|null $appKey Filter by app_key (optional)
     * @return array|null Matching app or null if not found
     */
    public function getApplicationByKey(?string $appKey = null): ?array
    {
        if (empty($appKey)) {
            return null;
        }

        $apps = $this->getApplications();

        if (isset($apps['applications'])) {
            foreach ($apps['applications'] as $app) {
                if (isset($app['app_key']) && $app['app_key'] === $appKey) {
                    return $app;
                }
            }
        }

        return null;
    }

    /**
     * Debug: Get raw HTTP response for applications endpoint.
     * 
     * Useful for debugging response structure and status.
     * 
     * @return array Debug information including status, headers, body
     */
    public function debugGetApplications(): array
    {
        return $this->debugFetch('/users/applications', 'BasicApplications');
    }

    /**
     * Debug: Get raw HTTP response for applications detail endpoint.
     * 
     * @return array Debug information including status, headers, body
     */
    public function debugGetApplicationsDetail(): array
    {
        return $this->debugFetch('/users/applications/detail', 'ApplicationsDetail');
    }

    /**
     * Debug: Get comprehensive debugging information.
     * 
     * Returns combined info about both endpoints, token status, and timing.
     * 
     * @return array
     * 
     * @example
     *   $debug = app(UserApplicationsService::class)->debugAll();
     *   dd($debug);
     */
    public function debugAll(): array
    {
        $startTime = microtime(true);

        return [
            'timestamp' => now()->toIso8601String(),
            'session' => [
                'id' => session()->getId(),
                'has_iam_token' => !empty(session('iam.access_token')),
                'has_iam_sub' => !empty(session('iam.sub')),
                'iam_sub' => session('iam.sub'),
                'iam_app' => session('iam.app'),
            ],
            'endpoints' => [
                'base_url' => IamConfig::baseUrl(),
                'applications' => IamConfig::userApplicationsEndpoint(),
                'applications_detail' => IamConfig::userApplicationsDetailEndpoint(),
            ],
            'basic_endpoint' => $this->debugGetApplications(),
            'detail_endpoint' => $this->debugGetApplicationsDetail(),
            'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
        ];
    }

    /**
     * Internal: Fetch data from IAM API with fallback handling.
     * 
     * @param string $endpoint API endpoint path (e.g., '/users/applications')
     * @param string $debugName Name for logging
     * @return array
     */
    private function fetchFromIam(string $endpoint, string $debugName): array
    {
        $iamAccessToken = session('iam.access_token') ?? session('iam.access_token_backup');

        if (empty($iamAccessToken)) {
            return $this->errorResponse('iam_token_missing', 'IAM access token not found in session', $endpoint);
        }

        $userId = Auth::id() ?? session('iam.sub');
        if (empty($userId)) {
            return $this->errorResponse('user_not_identified', 'Unable to identify user for caching', $endpoint);
        }

        // Generate cache key
        $cacheKey = $this->generateCacheKey($userId, $endpoint);

        // Try: 1. User-level cache (30 min, shared across sessions)
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            Log::debug("UserApplicationsService: {$debugName} from user cache", [
                'user_id' => $userId,
                'cache_key' => $cacheKey,
            ]);
            return $cached;
        }

        // Try: 2. Session-level cache (5 min, current session only)
        $sessionCacheKey = "iam:apps:session:" . session()->getId() . ":" . md5($endpoint);
        $sessionCached = session($sessionCacheKey);
        if ($sessionCached !== null) {
            Log::debug("UserApplicationsService: {$debugName} from session cache", [
                'session_id' => session()->getId(),
                'endpoint' => $endpoint,
            ]);
            // Restore to user cache for other sessions
            Cache::put($cacheKey, $sessionCached, 30 * 60);
            return $sessionCached;
        }

        // Fallback: 3. Fetch from IAM server
        try {
            $url = $this->buildIamUrl($endpoint);

            Log::info("UserApplicationsService: Fetching {$debugName} from IAM server", [
                'user_id' => $userId,
                'session_id' => session()->getId(),
                'endpoint' => $url,
            ]);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $iamAccessToken,
                'Accept' => 'application/json',
            ])->timeout(10)->get($url);

            if ($response->successful()) {
                $payload = $response->json();
                $result = array_merge(['source' => 'iam-server'], (array) $payload);

                // Cache in both layers
                Cache::put($cacheKey, $result, 30 * 60);  // User cache: 30 min
                session([$sessionCacheKey => $result]);     // Session cache: with session

                Log::info("UserApplicationsService: {$debugName} fetched and cached", [
                    'user_id' => $userId,
                    'status' => $response->status(),
                    'cache_ttl_minutes' => 30,
                ]);

                return $result;
            }

            Log::warning("UserApplicationsService: {$debugName} request failed", [
                'endpoint' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
                'user_id' => $userId,
            ]);

            return $this->errorResponse(
                'iam_server_error',
                "IAM server returned {$response->status()}",
                $endpoint,
                ['status' => $response->status(), 'body' => $response->body()]
            );
        } catch (\Throwable $e) {
            Log::error("UserApplicationsService: Exception calling IAM server", [
                'endpoint' => $endpoint,
                'message' => $e->getMessage(),
                'user_id' => $userId,
                'session_id' => session()->getId(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(
                'iam_request_error',
                $e->getMessage(),
                $endpoint
            );
        }
    }

    /**
     * Build the final IAM request URL from a package endpoint setting.
     *
     * Accepts a full URL or a relative IAM endpoint path.
     */
    private function buildIamUrl(string $endpoint): string
    {
        $endpoint = trim($endpoint);

        if (str_starts_with($endpoint, 'http://') || str_starts_with($endpoint, 'https://')) {
            return $endpoint;
        }

        $endpoint = ltrim($endpoint, '/');

        if (!str_starts_with($endpoint, 'api/')) {
            $endpoint = 'api/' . $endpoint;
        }

        return IamConfig::baseUrl() . '/' . $endpoint;
    }

    /**
     * Generate cache key for user-level application cache.
     * 
     * @param mixed $userId User ID
     * @param string $endpoint Endpoint path
     * @return string Cache key
     */
    private function generateCacheKey($userId, string $endpoint): string
    {
        return "iam:apps:user:{$userId}:" . md5($endpoint);
    }

    /**
     * Clear application cache for a specific user.
     * Call this on logout or permission change.
     * 
     * @param mixed $userId User ID (default: current auth user)
     * @return void
     */
    public static function clearUserAppCache($userId = null): void
    {
        $userId = $userId ?? Auth::id();
        if (empty($userId)) {
            return;
        }

        // Clear both endpoints cache
        $endpoints = ['/users/applications', '/users/applications/detail'];
        foreach ($endpoints as $endpoint) {
            $cacheKey = "iam:apps:user:{$userId}:" . md5($endpoint);
            Cache::forget($cacheKey);
        }

        Log::info("UserApplicationsService: Application cache cleared for user", [
            'user_id' => $userId,
        ]);
    }

    /**
     * Clear session-level application cache.
     * Call this when switching sessions within same browser.
     * 
     * @return void
     */
    public static function clearSessionAppCache(): void
    {
        $sessionId = session()->getId();
        $endpoints = ['/users/applications', '/users/applications/detail'];

        foreach ($endpoints as $endpoint) {
            $sessionCacheKey = "iam:apps:session:{$sessionId}:" . md5($endpoint);
            session()->forget($sessionCacheKey);
        }

        Log::debug("UserApplicationsService: Session application cache cleared", [
            'session_id' => $sessionId,
        ]);
    }


    /**
     * Internal: Debug fetch with raw response details.
     * 
     * @param string $endpoint API endpoint path
     * @param string $name Debug name
     * @return array
     */
    private function debugFetch(string $endpoint, string $name): array
    {
        $iamAccessToken = session('iam.access_token');
        $hasToken = !empty($iamAccessToken);

        if (!$hasToken) {
            return [
                'name' => $name,
                'status' => 'error',
                'error' => 'No IAM token in session',
                'token_present' => false,
            ];
        }

        try {
            $url = IamConfig::baseUrl() . '/api' . $endpoint;

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $iamAccessToken,
                'Accept' => 'application/json',
            ])->timeout(10)->get($url);

            return [
                'name' => $name,
                'url' => $url,
                'status' => $response->status(),
                'successful' => $response->successful(),
                'headers' => [
                    'content-type' => $response->header('content-type'),
                    'date' => $response->header('date'),
                ],
                'body_size' => strlen($response->body()),
                'body' => $response->json(),
            ];
        } catch (\Throwable $e) {
            return [
                'name' => $name,
                'status' => 'error',
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
            ];
        }
    }

    /**
     * Internal: Format error response.
     * 
     * @param string $code Error code
     * @param string $message Error message
     * @param string $endpoint Endpoint that failed
     * @param array $extra Extra debug data
     * @return array
     */
    private function errorResponse(string $code, string $message, string $endpoint, array $extra = []): array
    {
        return array_merge([
            'source' => 'iam-error',
            'error' => $code,
            'message' => $message,
            'endpoint' => $endpoint,
            'session_id' => session()->getId(),
        ], $extra);
    }
}
