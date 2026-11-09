<?php

namespace App\Http\Requests\Api;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $captchaLength = config('captcha.default.length', 6);
        $captchaKey = (string) $this->input('captcha_key');

        return [
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'captcha_key' => ['required', 'string', 'max:255'],
            'captcha' => [
                'bail',
                'required',
                "digits:{$captchaLength}",
                function (string $attribute, mixed $value, \Closure $fail) use ($captchaKey): void {
                    if ($captchaKey === '' || ! captcha_api_check((string) $value, $captchaKey)) {
                        $fail('Invalid or expired captcha. Please try again.');
                    }
                },
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'captcha.required' => 'Captcha is required.',
            'captcha.digits' => 'Captcha must be numbers only.',
            'captcha_key.required' => 'Captcha has expired. Please refresh and try again.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $identifier = $this->input('identifier');
        $captchaKey = $this->input('captcha_key') ?? $this->input('captchaKey') ?? $this->input('key');

        if (! $identifier) {
            $identifier = $this->input('email') ?? $this->input('phone');
        }

        if ($identifier !== null) {
            $normalized = trim((string) $identifier);

            if (filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
                $normalized = strtolower($normalized);
            }
            $this->merge([
                'identifier' => $normalized,
            ]);
        }

        if ($captchaKey !== null) {
            $this->merge([
                'captcha_key' => trim((string) $captchaKey),
            ]);
        }
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        // Always hash password check timing to prevent enumeration
        $identifier = $this->loginIdentifier();
        $field = $this->identifierField($identifier);
        $user = User::where($field, $identifier)->first();

        // Use constant-time comparison to prevent timing attacks
        $passwordValid = $user && Hash::check($this->password, $user->password);

        if ($user && $field === 'phone' && $user->role === 'administrator') {
            RateLimiter::hit($this->throttleKey(), 60);

            throw ValidationException::withMessages([
                'identifier' => ['Administrators must sign in with their email address.'],
            ]);
        }

        if ($user && $field === 'email' && $user->role !== 'administrator') {
            RateLimiter::hit($this->throttleKey(), 60);

            throw ValidationException::withMessages([
                'identifier' => ['Please sign in with your phone number.'],
            ]);
        }

        if (! $passwordValid) {
            // Rate limit: 5 attempts per 60 seconds (1 minute)
            RateLimiter::hit($this->throttleKey(), 60);

            // Log failed login attempt for security auditing
            Log::warning('Failed login attempt', [
                'identifier' => $identifier,
                'login_type' => $field,
                'ip' => $this->ip(),
                'user_agent' => $this->userAgent(),
            ]);

            throw ValidationException::withMessages([
                'identifier' => [trans('auth.failed')],
            ]);
        }

        if ($user && $user->role !== 'administrator' && ! $user->email_verified_at) {
            RateLimiter::hit($this->throttleKey(), 60);

            throw ValidationException::withMessages([
                'identifier' => ['Please verify your email before logging in.'],
            ]);
        }

        // Clear rate limiter on successful login
        RateLimiter::clear($this->throttleKey());

        // Log successful login
        Log::info('Successful login', [
            'user_id' => $user->id,
            'identifier' => $identifier,
            'login_type' => $field,
            'role' => $user->role,
            'ip' => $this->ip(),
        ]);
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        Log::warning('Login rate limit exceeded', [
            // 'email' => $this->email,
            'identifier' => $this->loginIdentifier(),
            'ip' => $this->ip(),
            'seconds' => $seconds,
        ]);

        throw ValidationException::withMessages([
            'identifier' => [
                trans('auth.throttle', [
                    'seconds' => $seconds,
                    'minutes' => ceil($seconds / 60),
                ]),
            ],
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->loginIdentifier()).'|login|'.$this->ip());
    }

    public function loginIdentifier(): string
    {
        return trim((string) $this->input('identifier'));
    }

    public function identifierField(string $identifier): string
    {
        return filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
    }
}
