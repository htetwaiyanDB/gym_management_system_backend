<x-guest-layout>
    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <!-- Validation Errors -->
    @if ($errors->any())
        <div class="mb-4 text-sm text-red-600">
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <!-- Email or Phone-->
        <div>
            <x-input-label for="identifier" value="Email or Phone" />
            <x-text-input
                id="identifier"
                class="block mt-1 w-full"
                type="text"
                name="identifier"
                :value="old('identifier')"
                required
                autofocus
                autocomplete="username"
            />
            <x-input-error :messages="$errors->get('identifier')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" value="Password" />
            <x-text-input
                id="password"
                class="block mt-1 w-full"
                type="password"
                name="password"
                required
                autocomplete="current-password"
            />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- CAPTCHA -->
        <div class="mt-4">
            <x-input-label for="captcha" value="Captcha" />

            <div class="flex items-center gap-3 mt-2">
                <span id="captcha-img">{!! captcha_img() !!}</span>
                <button type="button"
                        onclick="refreshCaptcha()"
                        class="inline-flex items-center px-3 py-2 border border-gray-300 rounded-md text-sm">
                    ↻
                </button>
            </div>

            <x-text-input
                id="captcha"
                class="block mt-2 w-full"
                type="text"
                name="captcha"
                required
                autocomplete="off"
                inputmode="numeric"
                pattern="[0-9]*"
                maxlength="{{ config('captcha.default.length', 6) }}"
                placeholder="Enter captcha"
            />
            <x-input-error :messages="$errors->get('captcha')" class="mt-2" />
        </div>

        <!-- Remember Me -->
        <div class="block mt-4">
            <label for="remember_me" class="inline-flex items-center">
                <input id="remember_me" type="checkbox"
                       class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                       name="remember">
                <span class="ms-2 text-sm text-gray-600">Remember me</span>
            </label>
        </div>

        <div class="flex items-center justify-end mt-4">
            @if (Route::has('password.request'))
                <a class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                   href="{{ route('password.request') }}">
                    Forgot your password?
                </a>
            @endif

            <x-primary-button class="ms-3">
                Log in
            </x-primary-button>
        </div>
    </form>

    <script>
        function refreshCaptcha() {
            fetch('/captcha-refresh')
                .then(res => res.json())
                .then(data => {
                    document.getElementById('captcha-img').innerHTML = data.captcha;
                });
        }
    </script>
</x-guest-layout>
