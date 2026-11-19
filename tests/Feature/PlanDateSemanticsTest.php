<?php

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

it('characterizes the current membership and trainer package date mismatch', function () {
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
        'end_date' => '2026-09-10',
    ])->assertCreated();

    $membership = MemberMembership::sole();
    $booking = TrainerBooking::sole();

    expect($membership->start_date->toDateString())->toBe('2026-08-10')
        ->and($membership->end_date->toDateString())->toBe('2026-09-09')
        ->and($booking->month_start_date->toDateString())->toBe('2026-08-10')
        ->and($booking->month_end_date->toDateString())->toBe('2026-09-10');

    $this->getJson('/api/subscriptions')
        ->assertOk()
        ->assertJsonPath('subscriptions.0.start_date', '2026-08-10')
        ->assertJsonPath('subscriptions.0.end_date', '2026-09-09');

    $this->getJson('/api/trainer-bookings')
        ->assertOk()
        ->assertJsonPath('bookings.0.start_date', '2026-08-10T00:00:00+00:00')
        ->assertJsonPath('bookings.0.end_date', '2026-09-10T00:00:00+00:00');
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

it('characterizes trainer booking dates as caller supplied at calendar boundaries', function (
    string $startDate,
    string $endDate,
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
        'end_date' => $endDate,
    ])->assertCreated();

    $booking = TrainerBooking::sole();

    expect($booking->month_start_date->toDateString())->toBe($startDate)
        ->and($booking->month_end_date->toDateString())->toBe($endDate);
})->with([
    'non-leap end of month' => ['2026-01-31', '2026-02-28'],
    'non-leap February' => ['2026-02-28', '2026-03-28'],
    'leap-year end of month' => ['2024-01-31', '2024-02-29'],
    'leap day' => ['2024-02-29', '2024-03-29'],
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
        ->and($booking->getRawOriginal('month_end_date'))->toBe('2026-09-10 00:30:00');

    $this->getJson('/api/trainer-bookings')
        ->assertOk()
        ->assertJsonPath('bookings.0.start_date', '2026-08-10T00:30:00+00:00')
        ->assertJsonPath('bookings.0.end_date', '2026-09-10T00:30:00+00:00');
});
