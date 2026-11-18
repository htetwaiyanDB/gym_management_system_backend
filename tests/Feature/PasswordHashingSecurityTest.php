<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

it('hashes passwords during public API registration', function () {
    Mail::fake();

    $plainPassword = 'Secure123!';

    $this->postJson('/api/register', [
        'name' => 'Secure User',
        'email' => 'secure@example.com',
        'phone' => '09111111111',
        'password' => $plainPassword,
        'password_confirmation' => $plainPassword,
        'role' => 'user',
    ])->assertCreated();

    $storedPassword = DB::table('users')
        ->where('email', 'secure@example.com')
        ->value('password');

    expect($storedPassword)
        ->not->toBe($plainPassword)
        ->and(Hash::isHashed($storedPassword))->toBeTrue()
        ->and(Hash::check($plainPassword, $storedPassword))->toBeTrue();
});

it('hashes passwords when an administrator creates or updates an API user', function () {
    $admin = User::factory()->create(['role' => 'administrator']);
    Sanctum::actingAs($admin);

    $createdPassword = 'Create123!';
    $this->postJson('/api/admin/register', [
        'user_id' => '12345',
        'name' => 'Created User',
        'email' => 'created@example.com',
        'phone' => '09222222222',
        'password' => $createdPassword,
        'password_confirmation' => $createdPassword,
        'role' => 'user',
    ])->assertCreated();

    $user = User::where('email', 'created@example.com')->firstOrFail();
    expect(Hash::check($createdPassword, $user->getRawOriginal('password')))->toBeTrue();

    $updatedPassword = 'Updated456!';
    $this->patchJson("/api/users/{$user->id}", [
        'password' => $updatedPassword,
        'password_confirmation' => $updatedPassword,
    ])->assertOk();

    $storedPassword = DB::table('users')->where('id', $user->id)->value('password');
    expect($storedPassword)
        ->not->toBe($updatedPassword)
        ->and(Hash::check($updatedPassword, $storedPassword))->toBeTrue();
});

it('hashes passwords when users update their own API profile', function () {
    $user = User::factory()->create(['role' => 'user']);
    Sanctum::actingAs($user);

    $plainPassword = 'Profile789!';
    $this->patchJson('/api/user/profile', [
        'password' => $plainPassword,
        'password_confirmation' => $plainPassword,
    ])->assertOk();

    $storedPassword = DB::table('users')->where('id', $user->id)->value('password');
    expect($storedPassword)
        ->not->toBe($plainPassword)
        ->and(Hash::check($plainPassword, $storedPassword))->toBeTrue();
});

it('hashes legacy plaintext passwords without changing existing hashes', function () {
    $existingHash = Hash::make('AlreadySecure123!');

    DB::table('users')->insert([
        [
            'name' => 'Legacy User',
            'email' => 'legacy@example.com',
            'password' => 'Legacy123!',
            'role' => 'user',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'name' => 'Hashed User',
            'email' => 'hashed@example.com',
            'password' => $existingHash,
            'role' => 'trainer',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $migration = require database_path('migrations/2026_11_18_020000_hash_legacy_plaintext_user_passwords.php');
    $migration->up();

    $legacyHash = DB::table('users')->where('email', 'legacy@example.com')->value('password');
    $unchangedHash = DB::table('users')->where('email', 'hashed@example.com')->value('password');

    expect($legacyHash)
        ->not->toBe('Legacy123!')
        ->and(Hash::check('Legacy123!', $legacyHash))->toBeTrue()
        ->and($unchangedHash)->toBe($existingHash);
});
