<?php

use App\Models\User;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('users can authenticate using the login screen', function (string $role, string $destination) {
    $user = User::factory()->create(['role' => $role, 'phone' => '09123456789']);

    $response = $this->withWebCaptcha()->post('/login', [
        'identifier' => $role === 'administrator' ? $user->email : $user->phone,
        'password' => 'password',
        'captcha' => '123456',
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route($destination));
    $this->assertAuthenticatedAs($user);
})->with([
    'administrator email' => ['administrator', 'dashboard'],
    'member phone' => ['user', 'user.home'],
    'trainer phone' => ['trainer', 'trainer.home'],
]);

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create(['role' => 'administrator']);

    $this->withWebCaptcha()->post('/login', [
        'identifier' => $user->email,
        'password' => 'wrong-password',
        'captcha' => '123456',
    ])->assertSessionHasErrors('identifier');

    $this->assertGuest();
});

test('web login rejects missing and incorrect captcha', function (?string $captcha) {
    $user = User::factory()->create(['role' => 'administrator']);

    $this->withWebCaptcha()->post('/login', [
        'identifier' => $user->email,
        'password' => 'password',
        'captcha' => $captcha,
    ])->assertSessionHasErrors('captcha');

    $this->assertGuest();
})->with([null, '654321']);

test('web login enforces role specific identifiers', function (string $role, string $field) {
    $user = User::factory()->create(['role' => $role, 'phone' => '09123456789']);

    $this->withWebCaptcha()->post('/login', [
        'identifier' => $user->{$field},
        'password' => 'password',
        'captcha' => '123456',
    ])->assertSessionHasErrors('identifier');

    $this->assertGuest();
})->with([
    ['administrator', 'phone'],
    ['user', 'email'],
    ['trainer', 'email'],
]);

test('members must verify their email before signing in', function (string $role) {
    $user = User::factory()->unverified()->create(['role' => $role, 'phone' => '09123456789']);

    $this->withWebCaptcha()->post('/login', [
        'identifier' => $user->phone,
        'password' => 'password',
        'captcha' => '123456',
    ])->assertSessionHasErrors('identifier');

    $this->assertGuest();
})->with(['user', 'trainer']);

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect('/');
});
