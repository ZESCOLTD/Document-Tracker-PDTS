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
                    :label="__('Man Number')"
                    :value="old('man_no')"
                    type="text"
                    required
                    autofocus
{{--                    placeholder="Enter your Man Number"--}}
                />
                @error('man_no')
                <span class="text-red-600 text-sm mt-1 block">{{ $message }}</span>
                @enderror
            </div>


            <!-- Password -->

            <div class="relative">
                <flux:input
                    name="password"
                    :label="__('Password')"
                    type="password"
                    required
                    autocomplete="current-password"
{{--                    :placeholder="__('Password')"--}}
                    viewable
                />

                <div class="flex justify-between items-center mt-2">
                    @error('password')
                    <span class="text-red-600 text-sm">{{ $message }}</span>
                    @else
                        <span></span>
                        @enderror

                        @if (Route::has('password.request'))
                            <flux:link
                                class="text-sm text-decoration-none"
                                :href="route('password.request')"
                                wire:navigate
                            >
                                {{ __('Forgot your password?') }}
                            </flux:link>
                        @endif
                </div>
            </div>

            <!-- Remember Me -->
            <div class="flex items-center justify-between">
                <flux:checkbox
                    name="remember"
                    :label="__('Remember me')"
                    :checked="old('remember')"
                />
            </div>

            <!-- Login Button -->
            <div class="pt-2">
                <flux:button
                    variant="primary"
                    type="submit"
                    class="w-full justify-center"
                    data-test="login-button"
                >
                    {{ __('Log in') }}
                </flux:button>
            </div>

            <!-- Register Link -->
            @if (Route::has('register'))
                <div class="text-center text-sm text-gray-600 pt-2">
                    {{ __("Don't have an account?") }}
                    <flux:link :href="route('register')" wire:navigate>
                        {{ __('Register') }}
                    </flux:link>
                </div>
            @endif
        </form>
    </div>
</x-layouts::auth>
