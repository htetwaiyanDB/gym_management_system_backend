<?php

use App\Mail\EmailVerificationCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
});

test('new users register and verify their email before being signed in', function (string $role) {
    Mail::fake();

    $response = $this->withWebCaptcha()->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'phone' => '09123456789',
        'role' => $role,
        'password' => 'password1234',
        'password_confirmation' => 'password1234',
        'captcha' => '123456',
    ]);

    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('verify.form'))
        ->assertSessionHas('email', 'test@example.com');
    $this->assertGuest();

    $user = User::where('email', 'test@example.com')->firstOrFail();
    expect($user->email_verified_at)->toBeNull();
    expect(Hash::check('password1234', $user->password))->toBeTrue();

    $code = null;
    Mail::assertSent(EmailVerificationCodeMail::class, function (EmailVerificationCodeMail $mail) use (&$code) {
        $code = $mail->code;

        return $mail->hasTo('test@example.com') && preg_match('/^\d{6}$/', $code) === 1;
    });

    $this->get('/verify-email-code')->assertOk()->assertDontSee($code);
    $this->post('/verify-email-code', ['email' => $user->email, 'code' => $code])
        ->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    expect($user->fresh()->email_verification_code)->toBeNull();
})->with(['user', 'trainer']);

test('registration rejects invalid captcha without creating an account', function (?string $captcha) {
    Mail::fake();

    $this->withWebCaptcha()->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'phone' => '09123456789',
        'role' => 'user',
        'password' => 'password1234',
        'password_confirmation' => 'password1234',
        'captcha' => $captcha,
    ])->assertSessionHasErrors('captcha');

    $this->assertGuest();
    $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    Mail::assertNothingSent();
})->with([null, '654321']);
