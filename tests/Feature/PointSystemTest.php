<?php

use App\Models\Point;
use App\Models\User;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('awards 50 points only once per day after first check-out', function () {
    $admin = User::factory()->create(['role' => 'administrator']);
    $member = User::factory()->create(['role' => 'user']);

    Sanctum::actingAs($admin);

    $this->postJson('/api/attendance/scan', [
        'user_id' => $member->id,
        'qr_type' => 'user',
    ])->assertOk()->assertJsonPath('record.action', 'check_in');

    $this->postJson('/api/attendance/scan', [
        'user_id' => $member->id,
        'qr_type' => 'user',
    ])->assertOk()->assertJsonPath('record.action', 'check_out');

    expect(Point::where('user_id', $member->id)->value('point'))->toBe(50);

    $this->postJson('/api/attendance/scan', [
        'user_id' => $member->id,
        'qr_type' => 'user',
    ])->assertOk()->assertJsonPath('record.action', 'check_in');

    $this->postJson('/api/attendance/scan', [
        'user_id' => $member->id,
        'qr_type' => 'user',
    ])->assertOk()->assertJsonPath('record.action', 'check_out');

    expect(Point::where('user_id', $member->id)->value('point'))->toBe(50);
});


it('awards user scan points on first check-out only for the day', function () {
    $member = User::factory()->create(['role' => 'user']);

    cache()->forever('attendance_qr_token_user', 'user-token');
    Sanctum::actingAs($member);

    $this->postJson('/api/user/check-in/scan', [
        'token' => 'user-token',
    ])->assertOk()->assertJsonPath('record.action', 'check_in');

    $this->postJson('/api/user/check-in/scan', [
        'token' => 'user-token',
    ])->assertOk()->assertJsonPath('record.action', 'check_out');

    $this->assertDatabaseHas('points', [
        'user_id' => $member->id,
        'point' => 50,
    ]);

    $this->postJson('/api/user/check-in/scan', [
        'token' => 'user-token',
    ])->assertOk()->assertJsonPath('record.action', 'check_in');

    $this->postJson('/api/user/check-in/scan', [
        'token' => 'user-token',
    ])->assertOk()->assertJsonPath('record.action', 'check_out');

    $this->assertDatabaseHas('points', [
        'user_id' => $member->id,
        'point' => 50,
    ]);
});

it('awards trainer scan points on first check-out only for the day', function () {
    $trainer = User::factory()->create(['role' => 'trainer']);

    cache()->forever('attendance_qr_token_trainer', 'trainer-token');
    Sanctum::actingAs($trainer);

    $this->postJson('/api/trainer/check-in/scan', [
        'token' => 'trainer-token',
    ])->assertOk()->assertJsonPath('record.action', 'check_in');

    $this->postJson('/api/trainer/check-in/scan', [
        'token' => 'trainer-token',
    ])->assertOk()->assertJsonPath('record.action', 'check_out');

    $this->assertDatabaseHas('points', [
        'user_id' => $trainer->id,
        'point' => 50,
    ]);

    $this->postJson('/api/trainer/check-in/scan', [
        'token' => 'trainer-token',
    ])->assertOk()->assertJsonPath('record.action', 'check_in');

    $this->postJson('/api/trainer/check-in/scan', [
        'token' => 'trainer-token',
    ])->assertOk()->assertJsonPath('record.action', 'check_out');

    $this->assertDatabaseHas('points', [
        'user_id' => $trainer->id,
        'point' => 50,
    ]);
});

it('awards rfid scan points on check-out and creates a points row when missing', function () {
    $member = User::factory()->create([
        'role' => 'user',
        'card_id' => 'RFID-0005',
    ]);

    Sanctum::actingAs($member);

    Carbon::setTestNow(now());

    $this->postJson('/api/attendance/rfid/scan', [
        'card_id' => 'RFID-0005',
    ])->assertOk()->assertJsonPath('attendance.action', 'check_in');

    Carbon::setTestNow(now()->addSeconds(2));

    $this->postJson('/api/attendance/rfid/scan', [
        'card_id' => 'RFID-0005',
    ])->assertOk()->assertJsonPath('attendance.action', 'check_out');

    $this->assertDatabaseHas('points', [
        'user_id' => $member->id,
        'point' => 50,
    ]);

    Carbon::setTestNow();
});


it('limits /api/points to the authenticated user for user and trainer roles', function () {
    $member = User::factory()->create(['role' => 'user']);
    $trainer = User::factory()->create(['role' => 'trainer']);

    Point::create(['user_id' => $member->id, 'point' => 80]);
    Point::create(['user_id' => $trainer->id, 'point' => 140]);

    Sanctum::actingAs($member);

    $this->getJson('/api/points')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.user_id', $member->id)
        ->assertJsonPath('data.0.point', 80);

    Sanctum::actingAs($trainer);

    $this->getJson('/api/points')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.user_id', $trainer->id)
        ->assertJsonPath('data.0.point', 140);
});

it('supports administrator CRUD for points endpoint', function () {
    $admin = User::factory()->create(['role' => 'administrator']);
    $member = User::factory()->create(['role' => 'user']);

    Sanctum::actingAs($admin);

    $createResponse = $this->postJson('/api/points', [
        'user_id' => $member->id,
        'point' => 125,
    ])->assertCreated()->assertJsonPath('data.point', 125);

    $pointId = $createResponse->json('data.id');

    $this->getJson('/api/points')
        ->assertOk()
        ->assertJsonPath('data.0.id', $pointId);

    $this->getJson('/api/points/' . $pointId)
        ->assertOk()
        ->assertJsonPath('data.user_id', $member->id);

    $this->patchJson('/api/points/' . $pointId, [
        'point' => 175,
    ])->assertOk()->assertJsonPath('data.point', 175);

    $this->deleteJson('/api/points/' . $pointId)
        ->assertOk();

    $this->assertDatabaseMissing('points', ['id' => $pointId]);
});
