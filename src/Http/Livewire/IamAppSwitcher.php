<?php

namespace Juniyasyos\IamClient\Http\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Juniyasyos\IamClient\Services\IamTokenManager;
use Juniyasyos\IamClient\Services\UserApplicationsService;

class IamAppSwitcher extends Component
{
    public array $applications = [];
    public ?string $error = null;
    public bool $loading = false;
    public bool $open = false;

    private IamTokenManager $tokenManager;
    private UserApplicationsService $applicationsService;
    private const CACHE_DURATION = 300;

    public function boot(IamTokenManager $tokenManager, UserApplicationsService $applicationsService): void
    {
        $this->tokenManager = $tokenManager;
        $this->applicationsService = $applicationsService;
    }

    public function mount(): void
    {
        if (config('iam.enabled') && session()->has('iam.sub')) {
            $this->loadApplications();
        }
    }

    private function getCacheKey(): string
    {
        return 'iam.apps.user.'
            . Auth::id()
            . ':' . session()->getId()
            . ':' . sha1((string) (session('iam.access_token') ?? session('iam.access_token_backup') ?? ''));
    }

    public function loadApplications(): void
    {
        $this->loading = true;
        $this->error = null;

        if (!config('iam.enabled') || !session()->has('iam.sub')) {
            $this->applications = [];
            $this->loading = false;
            return;
        }

        $cacheKey = $this->getCacheKey();
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            $this->applications = $cached;
            $this->loading = false;
            return;
        }

        $token = $this->tokenManager->getValidToken();

        if (empty($token)) {
            $this->applications = [];
            $this->error = 'Token IAM tidak tersedia. Silakan login ulang.';
            Log::warning('IamAppSwitcher (plugin): Missing IAM token', [
                'user_id' => Auth::id(),
            ]);
            $this->loading = false;
            return;
        }

        try {
            $applications = $this->applicationsService->getApplicationsForSwitcher();
            $this->applications = $applications;
            Cache::put($cacheKey, $applications, self::CACHE_DURATION);
        } catch (\Throwable $e) {
            $this->applications = [];
            $this->error = 'Terjadi kesalahan saat memuat aplikasi IAM.';
            Log::error('IamAppSwitcher (plugin): Exception loading applications', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);
        } finally {
            $this->loading = false;
        }
    }

    public function navigateTo(string $appUrl)
    {
        if (empty($appUrl)) {
            return;
        }

        Log::info('IamAppSwitcher (plugin): navigate to app', [
            'app_url' => $appUrl,
            'user_id' => Auth::id(),
        ]);

        return redirect($appUrl);
    }

    public function toggleOpen(): void
    {
        $this->open = ! $this->open;
    }

    public function refreshCache(): void
    {
        Cache::forget($this->getCacheKey());
        $this->loadApplications();
    }

    public function render()
    {
        return view('iam-client::livewire.iam-app-switcher');
    }
}
