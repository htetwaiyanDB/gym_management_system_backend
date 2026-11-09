<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

test('api login rejects a wrong captcha without issuing a token', function () {
    config()->set('captcha.disable', false);

    $user = User::factory()->create([
        'role' => 'administrator',
    ]);

    $captchaKey = Hash::make('123456');
    Cache::put('captcha_'.md5($captchaKey), '123456', 60);

    $this->postJson('/api/login', [
        'identifier' => $user->email,
        'password' => 'password',
        'captcha' => '654321',
        'captcha_key' => $captchaKey,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('captcha');

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('api login accepts a valid captcha and issues a token', function () {
    config()->set('captcha.disable', false);

    $user = User::factory()->create([
        'role' => 'administrator',
    ]);

    $captchaKey = Hash::make('123456');
    Cache::put('captcha_'.md5($captchaKey), '123456', 60);

    $this->postJson('/api/login', [
        'identifier' => $user->email,
        'password' => 'password',
        'captcha' => '123456',
        'key' => $captchaKey,
    ])
        ->assertOk()
        ->assertJsonStructure(['token', 'expires_at', 'user']);

    $this->assertDatabaseCount('personal_access_tokens', 1);
});

test('api login requires a captcha and its key', function () {
    config()->set('captcha.disable', false);

    $user = User::factory()->create([
        'role' => 'administrator',
    ]);

    $this->postJson('/api/login', [
        'identifier' => $user->email,
        'password' => 'password',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['captcha', 'captcha_key']);

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('captcha endpoints return a stateless challenge for the frontend', function (string $endpoint) {
    $this->getJson($endpoint)
        ->assertOk()
        ->assertJsonStructure(['key', 'img']);
})->with([
    '/api/captcha',
    '/api/captcha/refresh',
    '/api/captcha/api/default',
]);

test('legacy captcha endpoints keep the captcha html field for the current frontend', function (string $endpoint) {
    $this->getJson($endpoint)
        ->assertOk()
        ->assertJsonPath('captcha', fn (string $captcha) => str_starts_with($captcha, '<img src="data:image/'));
})->with([
    '/api/captcha',
    '/api/captcha/refresh',
]);
