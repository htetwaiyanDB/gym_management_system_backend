<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TrainerPackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrainerPackageController extends Controller
{
    public function index(): JsonResponse
    {
        $packages = TrainerPackage::query()
            ->orderBy('package_type')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $packages->map(fn (TrainerPackage $package) => $this->formatPackage($package)),
        ]);
    }

    public function show(TrainerPackage $trainerPackage): JsonResponse
    {
        return response()->json([
            'data' => $this->formatPackage($trainerPackage),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePackage($request);
        $package = TrainerPackage::create($validated);

        return response()->json([
            'message' => 'Trainer package created successfully.',
            'data' => $this->formatPackage($package),
        ], 201);
    }

    public function update(Request $request, TrainerPackage $trainerPackage): JsonResponse
    {
        $validated = $this->validatePackage($request, true);

        $trainerPackage->update($validated);

        return response()->json([
            'message' => 'Trainer package updated successfully.',
            'data' => $this->formatPackage($trainerPackage->fresh()),
        ]);
    }

    public function destroy(TrainerPackage $trainerPackage): JsonResponse
    {
        $trainerPackage->delete();

        return response()->json([
            'message' => 'Trainer package deleted successfully.',
        ]);
    }

    private function validatePackage(Request $request, bool $isUpdate = false): array
    {
        $requiredRule = $isUpdate ? 'sometimes' : 'required';

        $validated = $request->validate([
            'name' => [$requiredRule, 'string', 'max:255'],
            'package_type' => [$requiredRule, 'string', 'max:100'],
            'sessions_count' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'duration_months' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'price' => [$requiredRule, 'numeric', 'min:0'],
        ]);
        $validated['package_type'] = strtolower((string) ($validated['package_type'] ?? ''));

        return $validated;
    }

    private function formatPackage(TrainerPackage $package): array
    {
        return [
            'id' => $package->id,
            'name' => $package->name,
            'package_type' => $package->package_type,
            'sessions_count' => $package->sessions_count,
            'duration_months' => $package->duration_months,
            'price' => $package->price,
            'created_at' => $package->created_at?->toIso8601String(),
            'updated_at' => $package->updated_at?->toIso8601String(),
        ];
    }
}
