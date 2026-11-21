<?php

use App\Models\BoxingBooking;
use App\Models\BoxingPackage;
use App\Models\MemberMembership;
use App\Models\MembershipPlan;
use App\Models\TrainerBooking;
use App\Models\TrainerPackage;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create(['role' => 'administrator']));

    $this->member = User::factory()->create(['role' => 'user']);
    $this->trainer = User::factory()->create(['role' => 'trainer']);
});

it('uses the same fixed 30-day end date for membership and trainer monthly plans', function () {
    $membershipPlan = MembershipPlan::create([
        'name' => 'Date Semantics Membership',
        'duration_days' => 30,
        'price' => 100000,
        'plan_type' => 'membership',
        'is_active' => true,
    ]);
    $trainerPackage = TrainerPackage::create([
        'name' => 'Date Semantics Trainer Package',
        'package_type' => 'monthly',
        'sessions_count' => null,
        'duration_months' => 1,
        'price' => 200000,
    ]);

    $this->postJson('/api/subscriptions', [
        'member_id' => $this->member->id,
        'membership_plan_id' => $membershipPlan->id,
        'start_date' => '2026-08-10',
    ])->assertCreated();

    $this->postJson('/api/trainer-bookings', [
        'member_id' => $this->member->id,
        'trainer_id' => $this->trainer->id,
        'trainer_package_id' => $trainerPackage->id,
        'start_date' => '2026-08-10',
    ])->assertCreated();

    $membership = MemberMembership::sole();
    $booking = TrainerBooking::sole();

    expect($membership->start_date->toDateString())->toBe('2026-08-10')
        ->and($membership->end_date->toDateString())->toBe('2026-09-09')
        ->and($booking->month_start_date->toDateString())->toBe('2026-08-10')
        ->and($booking->month_end_date->toDateString())->toBe('2026-09-09');

    $this->getJson('/api/subscriptions')
        ->assertOk()
        ->assertJsonPath('subscriptions.0.start_date', '2026-08-10')
        ->assertJsonPath('subscriptions.0.end_date', '2026-09-09');

    $this->getJson('/api/trainer-bookings')
        ->assertOk()
        ->assertJsonPath('bookings.0.start_date', '2026-08-10T00:00:00+00:00')
        ->assertJsonPath('bookings.0.end_date', '2026-09-09T00:00:00+00:00');
});

it('characterizes fixed-day membership behavior at month and February boundaries', function (
    string $startDate,
    string $expectedEndDate,
) {
    $plan = MembershipPlan::create([
        'name' => "30 days from {$startDate}",
        'duration_days' => 30,
        'price' => 100000,
        'plan_type' => 'membership',
        'is_active' => true,
    ]);

    $this->postJson('/api/subscriptions', [
        'member_id' => $this->member->id,
        'membership_plan_id' => $plan->id,
        'start_date' => $startDate,
    ])->assertCreated();

    expect(MemberMembership::sole()->end_date->toDateString())->toBe($expectedEndDate);
})->with([
    'non-leap end of January' => ['2026-01-31', '2026-03-02'],
    'non-leap February' => ['2026-02-01', '2026-03-03'],
    'leap-year end of January' => ['2024-01-31', '2024-03-01'],
    'leap day' => ['2024-02-29', '2024-03-30'],
]);

it('calculates trainer monthly bookings as fixed 30-day periods and ignores a supplied end date', function (
    string $startDate,
    string $suppliedEndDate,
    string $expectedEndDate,
) {
    $package = TrainerPackage::create([
        'name' => "Calendar dates from {$startDate}",
        'package_type' => 'monthly',
        'sessions_count' => null,
        'duration_months' => 1,
        'price' => 200000,
    ]);

    $this->postJson('/api/trainer-bookings', [
        'member_id' => $this->member->id,
        'trainer_id' => $this->trainer->id,
        'trainer_package_id' => $package->id,
        'start_date' => $startDate,
        'end_date' => $suppliedEndDate,
    ])->assertCreated();

    $booking = TrainerBooking::sole();

    expect($booking->month_start_date->toDateString())->toBe($startDate)
        ->and($booking->month_end_date->toDateString())->toBe($expectedEndDate);
})->with([
    'non-leap end of month' => ['2026-01-31', '2026-02-28', '2026-03-02'],
    'non-leap February' => ['2026-02-28', '2026-03-28', '2026-03-30'],
    'leap-year end of month' => ['2024-01-31', '2024-02-29', '2024-03-01'],
    'leap day' => ['2024-02-29', '2024-03-29', '2024-03-30'],
]);

it('calculates boxing monthly bookings in the backend without an end date', function () {
    $package = BoxingPackage::create([
        'name' => 'Backend Calculated Boxing Package',
        'package_type' => 'monthly',
        'sessions_count' => null,
        'duration_months' => 2,
        'price' => 200000,
    ]);

    $this->postJson('/api/boxing-bookings', [
        'member_id' => $this->member->id,
        'trainer_id' => $this->trainer->id,
        'boxing_package_id' => $package->id,
        'start_date' => '2026-01-31',
    ])->assertCreated();

    $booking = BoxingBooking::sole();

    expect($booking->month_start_date->toDateString())->toBe('2026-01-31')
        ->and($booking->month_end_date->toDateString())->toBe('2026-04-01');
});

it('still requires an end date for session-based trainer and boxing bookings', function (
    string $endpoint,
    string $packageModel,
    string $packageKey,
) {
    $package = $packageModel::create([
        'name' => "Session package for {$endpoint}",
        'package_type' => 'sessions',
        'sessions_count' => 10,
        'duration_months' => null,
        'price' => 200000,
    ]);

    $this->postJson("/api/{$endpoint}", [
        'member_id' => $this->member->id,
        'trainer_id' => $this->trainer->id,
        $packageKey => $package->id,
        'start_date' => '2026-08-10',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('end_date');
})->with([
    'trainer' => ['trainer-bookings', TrainerPackage::class, 'trainer_package_id'],
    'boxing' => ['boxing-bookings', BoxingPackage::class, 'boxing_package_id'],
]);

it('characterizes timezone conversion at the trainer booking API boundary', function () {
    $package = TrainerPackage::create([
        'name' => 'Timezone Boundary Package',
        'package_type' => 'monthly',
        'sessions_count' => null,
        'duration_months' => 1,
        'price' => 200000,
    ]);

    $this->postJson('/api/trainer-bookings', [
        'member_id' => $this->member->id,
        'trainer_id' => $this->trainer->id,
        'trainer_package_id' => $package->id,
        'start_date' => '2026-08-10T00:30:00+06:30',
        'end_date' => '2026-09-10T00:30:00+06:30',
    ])->assertCreated();

    $booking = TrainerBooking::sole();

    expect($booking->getRawOriginal('month_start_date'))->toBe('2026-08-10 00:30:00')
        ->and($booking->getRawOriginal('month_end_date'))->toBe('2026-09-09 00:30:00');

    $this->getJson('/api/trainer-bookings')
        ->assertOk()
        ->assertJsonPath('bookings.0.start_date', '2026-08-10T00:30:00+00:00')
        ->assertJsonPath('bookings.0.end_date', '2026-09-09T00:30:00+00:00');
});
