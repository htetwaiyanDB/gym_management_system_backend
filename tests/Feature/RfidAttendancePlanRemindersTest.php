<?php

use App\Models\BoxingBooking;
use App\Models\BoxingPackage;
use App\Models\MemberMembership;
use App\Models\MembershipPlan;
use App\Models\TrainerBooking;
use App\Models\TrainerPackage;
use App\Models\User;
use App\Support\ActivePlanReminderService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Carbon::setTestNow('2026-11-20 10:00:00');

    $this->admin = User::factory()->create(['role' => 'administrator']);
    $this->member = User::factory()->create([
        'role' => 'user',
        'card_id' => 'RFID-REMINDER-TEST',
    ]);
    $this->trainer = User::factory()->create(['role' => 'trainer']);

    Sanctum::actingAs($this->admin);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('returns the existing check-in data with active membership, trainer, and boxing reminders', function () {
    $membership = createReminderMembership($this->member, [
        'plan_name' => '1 Month Plan',
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-30',
    ]);
    $trainerBooking = createTrainerReminderBooking($this->member, $this->trainer, [
        'package_name' => '12 Sessions',
        'start_date' => '2026-11-01',
        'end_date' => '2026-12-01',
    ]);
    $boxingBooking = createBoxingReminderBooking($this->member, $this->trainer, [
        'package_name' => 'Boxing Monthly',
        'start_date' => '2026-11-01',
        'end_date' => '2026-12-01',
    ]);

    $this->postJson('/api/attendance/rfid/scan', ['card_id' => $this->member->card_id])
        ->assertOk()
        ->assertJsonPath('message', 'Check-in recorded successfully.')
        ->assertJsonPath('user.id', $this->member->id)
        ->assertJsonPath('attendance.action', 'check_in')
        ->assertJsonPath('subscriptions.0.id', $membership->id)
        ->assertJsonPath('subscriptions.0.plan_name', '1 Month Plan')
        ->assertJsonPath('subscriptions.0.status', 'active')
        ->assertJsonPath('trainer_bookings.0.id', $trainerBooking->id)
        ->assertJsonPath('trainer_bookings.0.package_name', '12 Sessions')
        ->assertJsonPath('trainer_bookings.0.status', 'active')
        ->assertJsonPath('boxing_bookings.0.id', $boxingBooking->id)
        ->assertJsonPath('boxing_bookings.0.package_name', 'Boxing Monthly')
        ->assertJsonPath('boxing_bookings.0.status', 'active');
});

it('excludes expired, cancelled, completed, and on-hold plans', function () {
    createReminderMembership($this->member, [
        'end_date' => '2026-11-19',
        'is_expired' => true,
    ]);
    createReminderMembership($this->member, [
        'end_date' => '2026-12-20',
        'is_on_hold' => true,
    ]);
    createTrainerReminderBooking($this->member, $this->trainer, ['status' => 'cancelled']);
    createTrainerReminderBooking($this->member, $this->trainer, ['status' => 'completed']);
    createBoxingReminderBooking($this->member, $this->trainer, [
        'status' => 'on-hold',
        'hold_start_date' => '2026-11-15',
    ]);
    createBoxingReminderBooking($this->member, $this->trainer, [
        'status' => 'active',
        'end_date' => '2026-11-19',
    ]);

    $this->postJson('/api/attendance/rfid/scan', ['card_id' => $this->member->card_id])
        ->assertOk()
        ->assertJsonCount(0, 'subscriptions')
        ->assertJsonCount(0, 'trainer_bookings')
        ->assertJsonCount(0, 'boxing_bookings');
});

it('includes plans through the whole of their end date', function () {
    createReminderMembership($this->member, ['end_date' => '2026-11-20']);
    createTrainerReminderBooking($this->member, $this->trainer, ['end_date' => '2026-11-20']);
    createBoxingReminderBooking($this->member, $this->trainer, ['end_date' => '2026-11-20']);

    $this->postJson('/api/attendance/rfid/scan', ['card_id' => $this->member->card_id])
        ->assertOk()
        ->assertJsonPath('subscriptions.0.end_date', '2026-11-20')
        ->assertJsonPath('trainer_bookings.0.end_date', '2026-11-20')
        ->assertJsonPath('boxing_bookings.0.end_date', '2026-11-20');
});

it('returns empty reminder arrays when the user has no active plans', function () {
    $this->postJson('/api/attendance/rfid/scan', ['card_id' => $this->member->card_id])
        ->assertOk()
        ->assertJsonPath('attendance.action', 'check_in')
        ->assertJsonCount(0, 'subscriptions')
        ->assertJsonCount(0, 'trainer_bookings')
        ->assertJsonCount(0, 'boxing_bookings');
});

it('returns only the active record with the latest end date in each category', function () {
    createReminderMembership($this->member, ['plan_name' => 'Earlier', 'end_date' => '2026-11-25']);
    createReminderMembership($this->member, ['plan_name' => 'Latest', 'end_date' => '2026-12-25']);
    createTrainerReminderBooking($this->member, $this->trainer, [
        'package_name' => 'Earlier trainer',
        'end_date' => '2026-11-25',
    ]);
    createTrainerReminderBooking($this->member, $this->trainer, [
        'package_name' => 'Latest trainer',
        'end_date' => '2026-12-25',
    ]);

    $this->postJson('/api/attendance/rfid/scan', ['card_id' => $this->member->card_id])
        ->assertOk()
        ->assertJsonCount(1, 'subscriptions')
        ->assertJsonPath('subscriptions.0.plan_name', 'Latest')
        ->assertJsonCount(1, 'trainer_bookings')
        ->assertJsonPath('trainer_bookings.0.package_name', 'Latest trainer');
});

it('keeps check-out responses backward compatible and does not load reminders', function () {
    $this->postJson('/api/attendance/rfid/scan', ['card_id' => $this->member->card_id])
        ->assertOk()
        ->assertJsonPath('attendance.action', 'check_in');

    $loader = Mockery::mock(ActivePlanReminderService::class);
    $loader->shouldNotReceive('forUser');
    $this->app->instance(ActivePlanReminderService::class, $loader);

    Carbon::setTestNow('2026-11-20 10:00:02');

    $this->postJson('/api/attendance/rfid/scan', ['card_id' => $this->member->card_id])
        ->assertOk()
        ->assertJsonPath('message', 'Check-out recorded successfully.')
        ->assertJsonPath('user.id', $this->member->id)
        ->assertJsonPath('attendance.action', 'check_out')
        ->assertJsonMissingPath('subscriptions')
        ->assertJsonMissingPath('trainer_bookings')
        ->assertJsonMissingPath('boxing_bookings');
});

it('does not fail or roll back check-in when reminder loading fails', function () {
    Log::spy();

    $loader = Mockery::mock(ActivePlanReminderService::class);
    $loader->shouldReceive('forUser')->once()->andThrow(new RuntimeException('Reminder database unavailable'));
    $this->app->instance(ActivePlanReminderService::class, $loader);

    $this->postJson('/api/attendance/rfid/scan', ['card_id' => $this->member->card_id])
        ->assertOk()
        ->assertJsonPath('attendance.action', 'check_in')
        ->assertJsonCount(0, 'subscriptions')
        ->assertJsonCount(0, 'trainer_bookings')
        ->assertJsonCount(0, 'boxing_bookings');

    $this->assertDatabaseHas('rfid_attendances', [
        'user_id' => $this->member->id,
        'attendance_date' => '2026-11-20 00:00:00',
    ]);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === 'Unable to load active plan reminders after RFID check-in.'
            && $context['user_id'] === $this->member->id
        );
});

function createReminderMembership(User $member, array $overrides = []): MemberMembership
{
    $plan = MembershipPlan::create([
        'name' => $overrides['plan_name'] ?? 'Membership reminder',
        'duration_days' => 30,
        'price' => 100000,
        'plan_type' => 'membership',
        'is_active' => true,
    ]);

    return MemberMembership::create([
        'member_id' => $member->id,
        'membership_plan_id' => $plan->id,
        'discount_percentage' => 0,
        'final_price' => 100000,
        'start_date' => $overrides['start_date'] ?? '2026-11-01',
        'end_date' => $overrides['end_date'] ?? '2026-12-01',
        'is_expired' => $overrides['is_expired'] ?? false,
        'is_on_hold' => $overrides['is_on_hold'] ?? false,
        'hold_started_at' => ($overrides['is_on_hold'] ?? false) ? '2026-11-15' : null,
    ]);
}

function createTrainerReminderBooking(User $member, User $trainer, array $overrides = []): TrainerBooking
{
    $package = TrainerPackage::create([
        'name' => $overrides['package_name'] ?? 'Trainer reminder',
        'package_type' => 'personal',
        'sessions_count' => 12,
        'price' => 300000,
    ]);

    return TrainerBooking::create([
        'member_id' => $member->id,
        'trainer_id' => $trainer->id,
        'trainer_package_id' => $package->id,
        'sessions_count' => 12,
        'sessions_remaining' => 12,
        'sessions_start_date' => $overrides['start_date'] ?? '2026-11-01',
        'sessions_end_date' => $overrides['end_date'] ?? '2026-12-01',
        'hold_start_date' => $overrides['hold_start_date'] ?? null,
        'price_per_session' => 25000,
        'total_price' => 300000,
        'status' => $overrides['status'] ?? 'active',
        'paid_status' => 'paid',
    ]);
}

function createBoxingReminderBooking(User $member, User $trainer, array $overrides = []): BoxingBooking
{
    $package = BoxingPackage::create([
        'name' => $overrides['package_name'] ?? 'Boxing reminder',
        'package_type' => 'one to one',
        'sessions_count' => 12,
        'price' => 300000,
    ]);

    return BoxingBooking::create([
        'member_id' => $member->id,
        'trainer_id' => $trainer->id,
        'boxing_package_id' => $package->id,
        'sessions_count' => 12,
        'sessions_remaining' => 12,
        'sessions_start_date' => $overrides['start_date'] ?? '2026-11-01',
        'sessions_end_date' => $overrides['end_date'] ?? '2026-12-01',
        'hold_start_date' => $overrides['hold_start_date'] ?? null,
        'price_per_session' => 25000,
        'total_price' => 300000,
        'status' => $overrides['status'] ?? 'active',
        'paid_status' => 'paid',
    ]);
}
