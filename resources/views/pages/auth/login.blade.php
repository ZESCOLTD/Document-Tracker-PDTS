<x-layouts::auth :title="__('Log in')">
    <div class="mx-auto w-full max-w-md flex flex-col gap-6">
{{--        <x-auth-header--}}
{{--            :title="__('Welcome back!')"--}}
{{--                :description="__('Please enter your credentials to access your account.')"--}}
{{--        />--}}

        <x-auth-session-status class="text-center" :status="session('status')" />

        @if ($errors->any())
            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-600 text-center">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="flex flex-col gap-5">
            @csrf

            <!-- Man Number -->
            <div class="w-full">
                <flux:input
                    name="man_no"
                    icon="user"
                    :placeholder="__('Man Number')"
                    aria-label="{{ __('Man Number') }}"
                    :value="old('man_no')"
                    type="text"
                    required
                    autofocus
                    class="[&_input]:h-12 [&_input]:rounded-xl [&_input]:border-[#CFE3D6] [&_input]:bg-[#F6FAF7] [&_input]:text-slate-900 [&_svg]:!text-[#4B5F55]"
                />
                @error('man_no')
                <span class="text-red-600 text-sm mt-1 block">{{ $message }}</span>
                @enderror
            </div>


            <!-- Password -->

            <div class="w-full">
                <flux:input
                    name="password"
                    icon="lock-closed"
                    :placeholder="__('Password')"
                    aria-label="{{ __('Password') }}"
                    type="password"
                    required
                    autocomplete="current-password"
                    viewable
                    class="[&_input]:h-12 [&_input]:rounded-xl [&_input]:border-[#CFE3D6] [&_input]:bg-[#F6FAF7] [&_input]:text-slate-900 [&_svg]:!text-[#4B5F55]"
                />
                @error('password')
                <span class="text-red-600 text-sm mt-1 block">{{ $message }}</span>
                @enderror
            </div>

            <!-- Remember Me + Forgot Password -->
            <div class="flex items-center justify-between">
                <div class="[&_ui-label]:!text-[#075B35] [&_ui-checkbox]:!border-[#7FB596] [&_ui-checkbox]:!bg-white">
                    <flux:checkbox
                        name="remember"
                        :label="__('Remember me')"
                        :checked="old('remember')"
                    />
                </div>

                @if (Route::has('password.request'))
                    <flux:link
                        class="text-sm font-medium !text-[#F58220] hover:underline"
                        :href="route('password.request')"
                        wire:navigate
                    >
                        {{ __('Forgot Password?') }}
                    </flux:link>
                @endif
            </div>

            <!-- Login Button -->
            <div class="pt-2">
                <button
                    type="submit"
                    data-test="login-button"
                    class="flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-[#075B35] px-4 text-sm font-semibold text-white shadow-lg shadow-[#075B35]/25 transition hover:-translate-y-0.5 hover:bg-[#00452B] hover:shadow-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-[#F58220] focus-visible:ring-offset-2"
                >
                    {{ __('Sign In') }}
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" />
                    </svg>
                </button>
            </div>

        </form>
    </div>
</x-layouts::auth>
