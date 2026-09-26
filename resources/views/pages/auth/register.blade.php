<x-layouts::auth :title="__('Register')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Create an account')" :description="__('Enter your details below to create your account')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-6">
            @csrf
            <!-- Name -->
            <flux:input
                name="name"
                :label="__('Name')"
                :value="old('name')"
                type="text"
                required
                autofocus
                autocomplete="name"
                :placeholder="__('Full name')"
            />

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autocomplete="email"
                placeholder="email@example.com"
            />

            <!-- Password -->
            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <!-- Confirm Password -->
            <flux:input
                name="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Confirm password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            @php($terms = app(App\Services\Legal\LegalDocuments::class)->current(App\Enums\LegalDocumentType::Terms))
            @if ($terms)
                <flux:field variant="inline">
                    <flux:checkbox name="terms" value="1" :checked="old('terms')" data-test="register-terms" />
                    <flux:label>Prijímam <a href="{{ route('legal.show', ['slug' => 'vop']) }}" target="_blank" rel="noopener" class="underline">obchodné podmienky</a> (verzia {{ $terms->version }})</flux:label>
                </flux:field>
                @error('terms') <flux:text class="-mt-4 text-sm text-red-600">{{ $message }}</flux:text> @enderror
            @endif
            <flux:text class="text-xs">Ako spracúvame údaje potrebné na prevádzku účtu, sa dočítaš v <a href="{{ route('legal.show', ['slug' => 'ochrana-osobnych-udajov']) }}" target="_blank" rel="noopener" class="underline">informáciách o súkromí</a>. Súhlas so spracúvaním na prevádzku účtu nepotrebujeme; voliteľná analytika sa riadi tvojou voľbou cookies.</flux:text>

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" data-test="register-user-button">
                    {{ __('Create account') }}
                </flux:button>
            </div>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-zinc-400">
            <span>{{ __('Already have an account?') }}</span>
            <flux:link :href="route('login')" wire:navigate>{{ __('Log in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
