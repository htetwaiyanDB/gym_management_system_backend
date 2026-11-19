<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BoxingPackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BoxingPackageController extends Controller
{
    public function index(): JsonResponse
    {
        $packages = BoxingPackage::query()
            ->orderBy('package_type')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $packages->map(fn (BoxingPackage $package) => $this->formatPackage($package)),
        ]);
    }

    public function show(BoxingPackage $boxingPackage): JsonResponse
    {
        return response()->json([
            'data' => $this->formatPackage($boxingPackage),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePackage($request);
        $package = BoxingPackage::create($validated);

        return response()->json([
            'message' => 'Boxing package created successfully.',
            'data' => $this->formatPackage($package),
        ], 201);
    }

    public function update(Request $request, BoxingPackage $boxingPackage): JsonResponse
    {
        $validated = $this->validatePackage($request, true);

        $boxingPackage->update($validated);

        return response()->json([
            'message' => 'Boxing package updated successfully.',
            'data' => $this->formatPackage($boxingPackage->fresh()),
        ]);
    }

    public function destroy(BoxingPackage $boxingPackage): JsonResponse
    {
        $boxingPackage->delete();

        return response()->json([
            'message' => 'Boxing package deleted successfully.',
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

    private function formatPackage(BoxingPackage $package): array
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
