@php($label = config('iam.filament.login_button_text', 'Login via IAM'))

@if (Route::has('iam.sso.login.filament'))
    <div class="mt-4">
        <x-filament::button
            tag="a"
            href="{{ route('iam.sso.login.filament') }}"
            class="w-full justify-center"
        >
            {{ $label }}
        </x-filament::button>
    </div>
@endif
