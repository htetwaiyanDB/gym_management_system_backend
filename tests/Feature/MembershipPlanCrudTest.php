<?php

use App\Models\MemberMembership;
use App\Models\MembershipPlan;
use App\Models\PricingSetting;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'administrator']);
    Sanctum::actingAs($this->admin);
});

it('allows only administrators to list and manage plans', function () {
    MembershipPlan::create([
        'name' => 'Visible Plan',
        'duration_days' => 30,
        'price' => 120000,
        'plan_type' => 'membership',
        'is_active' => true,
    ]);

    $this->getJson('/api/membership-plans')
        ->assertOk()
        ->assertJsonPath('plans.0.name', 'Visible Plan');

    Sanctum::actingAs(User::factory()->create(['role' => 'user']));

    $this->getJson('/api/membership-plans')->assertForbidden();
    $this->postJson('/api/membership-plans', [
        'name' => 'Forbidden Plan',
        'duration_days' => 30,
        'price' => 100,
        'plan_type' => 'membership',
    ])->assertForbidden();
});

it('creates membership and class plans', function () {
    $this->postJson('/api/membership-plans', [
        'name' => '2 Months Plan',
        'duration_days' => 60,
        'price' => 220000,
        'plan_type' => 'membership',
        'is_active' => true,
    ])->assertCreated()
        ->assertJsonPath('message', 'Membership plan created successfully.')
        ->assertJsonPath('plan.name', '2 Months Plan');

    $this->postJson('/api/membership-plans', [
        'name' => 'Class 3 Months',
        'duration_days' => 90,
        'price' => 200000,
        'plan_type' => 'class',
    ])->assertCreated()
        ->assertJsonPath('plan.plan_type', 'class')
        ->assertJsonPath('plan.is_active', true);
});

it('rejects duplicate names and invalid plan values', function () {
    MembershipPlan::create([
        'name' => 'Existing Plan',
        'duration_days' => 30,
        'price' => 100,
        'plan_type' => 'membership',
        'is_active' => true,
    ]);

    $this->postJson('/api/membership-plans', [
        'name' => 'Existing Plan',
        'duration_days' => 0,
        'price' => -1,
        'plan_type' => 'invalid',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'duration_days', 'price', 'plan_type']);
});

it('supports full and partial updates', function () {
    $plan = MembershipPlan::create([
        'name' => 'Original Plan',
        'duration_days' => 30,
        'price' => 100000,
        'plan_type' => 'membership',
        'is_active' => true,
    ]);

    $this->putJson("/api/membership-plans/{$plan->id}", [
        'name' => 'Updated Plan',
        'duration_days' => 45,
        'price' => 150000,
        'plan_type' => 'membership',
        'is_active' => true,
    ])->assertOk()->assertJsonPath('plan.duration_days', 45);

    $this->patchJson("/api/membership-plans/{$plan->id}", ['price' => 175000])
        ->assertOk()
        ->assertJsonPath('plan.name', 'Updated Plan');

    expect((float) $plan->fresh()->price)->toBe(175000.0);
});

it('archives, filters, includes and restores plans without deleting them', function () {
    $plan = MembershipPlan::create([
        'name' => 'Archivable Plan',
        'duration_days' => 30,
        'price' => 100000,
        'plan_type' => 'membership',
        'is_active' => true,
    ]);

    $this->deleteJson("/api/membership-plans/{$plan->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Membership plan archived successfully.');

    $this->assertDatabaseHas('membership_plans', ['id' => $plan->id, 'is_active' => false]);
    $this->getJson('/api/membership-plans')->assertJsonMissing(['id' => $plan->id]);
    $this->getJson('/api/membership-plans?include_inactive=1')
        ->assertJsonFragment(['id' => $plan->id, 'is_active' => false]);

    $this->deleteJson("/api/membership-plans/{$plan->id}")->assertOk();
    $this->patchJson("/api/membership-plans/{$plan->id}", ['is_active' => true])
        ->assertOk()
        ->assertJsonPath('plan.is_active', true);
});

it('excludes archived plans from options and rejects them for new subscriptions', function () {
    $member = User::factory()->create(['role' => 'user']);
    $active = MembershipPlan::create([
        'name' => 'Active Custom',
        'duration_days' => 40,
        'price' => 140000,
        'plan_type' => 'membership',
        'is_active' => true,
    ]);
    $archived = MembershipPlan::create([
        'name' => 'Archived Custom',
        'duration_days' => 50,
        'price' => 150000,
        'plan_type' => 'membership',
        'is_active' => false,
    ]);

    $this->getJson('/api/subscriptions/options')
        ->assertOk()
        ->assertJsonFragment(['id' => $active->id, 'name' => 'Active Custom'])
        ->assertJsonMissing(['id' => $archived->id, 'name' => 'Archived Custom']);

    $this->postJson('/api/subscriptions', [
        'member_id' => $member->id,
        'membership_plan_id' => $archived->id,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['membership_plan_id']);
});

it('uses custom prices and keeps discount calculations working', function () {
    $member = User::factory()->create(['role' => 'user']);
    $plan = MembershipPlan::create([
        'name' => 'Custom 45 Day',
        'duration_days' => 45,
        'price' => 200000,
        'plan_type' => 'membership',
        'is_active' => true,
    ]);

    $this->postJson('/api/subscriptions', [
        'member_id' => $member->id,
        'membership_plan_id' => $plan->id,
        'start_date' => '2026-11-01',
        'discount_percentage' => 10,
    ])->assertCreated();

    $subscription = MemberMembership::firstOrFail();
    expect((float) $subscription->final_price)->toBe(180000.0)
        ->and($subscription->end_date->toDateString())->toBe('2026-12-16');
});

it('uses legacy pricing for null plan prices and preserves archived plan history', function () {
    PricingSetting::create([
        'monthly_subscription_price' => 80000,
        'three_month_subscription_price' => 240000,
        'quarterly_subscription_price' => 400000,
        'annual_subscription_price' => 960000,
        'class_subscription_price' => 70000,
        'class_three_month_price' => 200000,
        'class_six_month_price' => 380000,
        'class_twelve_month_price' => 700000,
    ]);
    $member = User::factory()->create(['role' => 'user']);
    $plan = MembershipPlan::create([
        'name' => 'Legacy Monthly',
        'duration_days' => 30,
        'price' => null,
        'plan_type' => 'membership',
        'is_active' => true,
    ]);

    $this->postJson('/api/subscriptions', [
        'member_id' => $member->id,
        'membership_plan_id' => $plan->id,
    ])->assertCreated();

    $plan->update(['is_active' => false]);

    expect((float) MemberMembership::firstOrFail()->final_price)->toBe(80000.0);
    $this->getJson("/api/users/{$member->id}/records")
        ->assertOk()
        ->assertJsonPath('subscriptions.0.plan_name', 'Legacy Monthly')
        ->assertJsonPath('subscriptions.0.plan.is_active', false);
});

it('keeps legacy pricing endpoints compatible and syncs existing plans without activating or creating them', function () {
    $plan = MembershipPlan::create([
        'name' => '1 Month',
        'duration_days' => 30,
        'price' => 80000,
        'plan_type' => 'membership',
        'is_active' => false,
    ]);

    $this->putJson('/api/pricing/one-month', ['one_month_subscription_price' => 125000])
        ->assertOk()
        ->assertJsonPath('pricing.one_month_subscription_price', '125000.00');

    $plan->refresh();
    expect((float) $plan->price)->toBe(125000.0)
        ->and($plan->is_active)->toBeFalse();

    $this->putJson('/api/pricing/three-months', ['three_month_subscription_price' => 300000])->assertOk();
    $this->putJson('/api/pricing/six-months', ['six_month_subscription_price' => 500000])->assertOk();
    $this->putJson('/api/pricing/twelve-months', ['twelve_month_subscription_price' => 900000])->assertOk();
    $this->putJson('/api/pricing/class', ['class_subscription_price' => 90000])->assertOk();
    $this->putJson('/api/pricing/class-plans/three-months', ['price' => 250000])->assertOk();
    $this->putJson('/api/pricing/class-plans/six-months', ['price' => 450000])->assertOk();
    $this->putJson('/api/pricing/class-plans/twelve-months', ['price' => 800000])->assertOk();
    $this->getJson('/api/pricing')
        ->assertOk()
        ->assertJsonStructure(['subscription_prices', 'class_prices', 'trainers']);

    $this->assertDatabaseMissing('membership_plans', ['name' => '3 Months']);
    $this->assertDatabaseMissing('membership_plans', ['name' => 'Class']);
});

it('keeps trainer and boxing package CRUD routes registered', function () {
    $routes = collect(Route::getRoutes()->getRoutes());

    foreach (['trainer-packages', 'boxing-packages'] as $prefix) {
        foreach (['GET', 'POST'] as $method) {
            expect($routes->contains(fn ($route) => $route->uri() === "api/{$prefix}"
                && in_array($method, $route->methods(), true)))->toBeTrue();
        }

        foreach (['GET', 'PUT', 'PATCH', 'DELETE'] as $method) {
            expect($routes->contains(fn ($route) => str_starts_with($route->uri(), "api/{$prefix}/{")
                && in_array($method, $route->methods(), true)))->toBeTrue();
        }
    }
});
