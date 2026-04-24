<div>
    @if (config('iam.enabled'))
    <div class="relative">

        <!-- Button -->
        <button
            wire:click="toggleOpen"
            type="button"
            class="inline-flex items-center gap-2 px-3 py-2 rounded-lg
                   text-gray-600 dark:text-gray-300
                   hover:bg-gray-100 dark:hover:bg-gray-800
                   hover:text-gray-900 dark:hover:text-white
                   transition-colors"
            title="Aplikasi">

            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h4a2 2 0 012 2v4a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h4a2 2 0 012 2v4a2 2 0 01-2 2h-4a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h4a2 2 0 012 2v4a2 2 0 01-2 2H6a2 2 0 01-2-2v-4zM14 16a2 2 0 012-2h4a2 2 0 012 2v4a2 2 0 01-2 2h-4a2 2 0 01-2-2v-4z" />
            </svg>

            <span class="text-sm hidden sm:inline">Aplikasi</span>
        </button>

        @if($open)
        <div class="absolute right-0 mt-1 w-80 sm:w-96 z-50
                    bg-white dark:bg-gray-900
                    border border-gray-200 dark:border-gray-800
                    rounded-xl shadow-lg overflow-hidden">

            <!-- Header -->
            <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800">
                <p class="text-sm font-semibold text-gray-800 dark:text-gray-100">
                    Applications
                </p>
                <p class="text-xs text-gray-500 mt-0.5">
                    {{ count($applications) }} tersedia
                </p>
            </div>

            <div class="max-h-80 overflow-y-auto">

                <!-- Loading -->
                @if($loading)
                <div class="p-4 space-y-2 animate-pulse">
                    <div class="h-4 bg-gray-200 dark:bg-gray-800 rounded w-3/4"></div>
                    <div class="h-4 bg-gray-200 dark:bg-gray-800 rounded w-1/2"></div>
                </div>
                @endif

                <!-- Error -->
                @if($error)
                <div class="p-4 text-sm text-red-600">
                    {{ $error }}
                </div>
                @endif

                <!-- List -->
                @forelse ($applications as $app)
                <button
                    wire:click="navigateTo('{{ $app['app_url'] }}')"
                    type="button"
                    class="w-full flex items-center gap-3 px-4 py-3
                           hover:bg-gray-50 dark:hover:bg-gray-800
                           transition-colors text-left">

                    <!-- Icon -->
                    <div class="w-9 h-9 rounded-lg overflow-hidden
                                bg-gray-100 dark:bg-gray-800
                                flex items-center justify-center flex-shrink-0">

                        @if($app['logo_url'])
                        <img src="{{ $app['logo_url'] }}" class="w-full h-full object-cover">
                        @else
                        <svg class="w-4 h-4 text-gray-500" fill="currentColor" viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="10" />
                        </svg>
                        @endif
                    </div>

                    <!-- Content -->
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-gray-900 dark:text-white truncate">
                            {{ $app['name'] }}
                        </p>

                        <!-- role (ringkas) -->
                        @if(!empty($app['roles']))
                        <p class="text-xs text-gray-500 truncate">
                            {{ $app['roles'][0]['name'] }}
                            @if(count($app['roles']) > 1)
                            +{{ count($app['roles']) - 1 }}
                            @endif
                        </p>
                        @endif
                    </div>

                    <!-- Status dot -->
                    @if($app['status'] === 'active')
                    <span class="w-2 h-2 rounded-full bg-green-500"></span>
                    @endif

                    <!-- Arrow -->
                    <svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </button>

                @empty
                @if(!$loading)
                <div class="p-6 text-center text-sm text-gray-500">
                    Tidak ada aplikasi
                </div>
                @endif
                @endforelse

            </div>
        </div>

        <!-- Overlay -->
        <div class="fixed inset-0 z-40" wire:click="toggleOpen"></div>
        @endif

    </div>
    @endif
</div>