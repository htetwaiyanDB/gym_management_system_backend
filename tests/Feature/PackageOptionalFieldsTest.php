<?php

use App\Models\BoxingPackage;
use App\Models\TrainerPackage;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create(['role' => 'administrator']));
});

it('creates trainer and boxing packages without sessions or duration', function (string $endpoint, string $modelClass) {
    $response = $this->postJson("/api/{$endpoint}", [
        'name' => 'Flexible Package',
        'package_type' => 'custom',
        'price' => 150000,
    ])->assertCreated()
        ->assertJsonPath('data.sessions_count', null)
        ->assertJsonPath('data.duration_months', null);

    $package = $modelClass::findOrFail($response->json('data.id'));

    expect($package->sessions_count)
        ->toBeNull()
        ->and($package->duration_months)->toBeNull();
})->with([
    'trainer package' => ['trainer-packages', TrainerPackage::class],
    'boxing package' => ['boxing-packages', BoxingPackage::class],
]);

it('accepts explicit null sessions and duration values', function (string $endpoint) {
    $this->postJson("/api/{$endpoint}", [
        'name' => 'No Limit Package',
        'package_type' => 'custom',
        'sessions_count' => null,
        'duration_months' => null,
        'price' => 200000,
    ])->assertCreated()
        ->assertJsonPath('data.sessions_count', null)
        ->assertJsonPath('data.duration_months', null);
})->with([
    'trainer package' => ['trainer-packages'],
    'boxing package' => ['boxing-packages'],
]);

it('allows optional package limits to be cleared during update', function (string $endpoint, string $modelClass) {
    $package = $modelClass::create([
        'name' => 'Limited Package',
        'package_type' => 'custom',
        'sessions_count' => 10,
        'duration_months' => 2,
        'price' => 250000,
    ]);

    $this->patchJson("/api/{$endpoint}/{$package->id}", [
        'sessions_count' => null,
        'duration_months' => null,
    ])->assertOk()
        ->assertJsonPath('data.sessions_count', null)
        ->assertJsonPath('data.duration_months', null);

    expect($package->fresh()->sessions_count)
        ->toBeNull()
        ->and($package->fresh()->duration_months)->toBeNull();
})->with([
    'trainer package' => ['trainer-packages', TrainerPackage::class],
    'boxing package' => ['boxing-packages', BoxingPackage::class],
]);
