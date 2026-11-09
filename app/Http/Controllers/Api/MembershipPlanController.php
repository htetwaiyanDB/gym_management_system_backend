<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MembershipPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class MembershipPlanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'include_inactive' => ['sometimes', 'boolean'],
            'plan_type' => ['sometimes', Rule::in(['membership', 'class'])],
        ]);

        $plans = MembershipPlan::query()
            ->when(! $request->boolean('include_inactive'), fn ($query) => $query->where('is_active', true))
            ->when(isset($validated['plan_type']), fn ($query) => $query->where('plan_type', $validated['plan_type']))
            ->orderBy('plan_type')
            ->orderBy('duration_days')
            ->orderBy('id')
            ->get();

        return response()->json(['plans' => $plans]);
    }

    public function show(MembershipPlan $membershipPlan): JsonResponse
    {
        return response()->json(['plan' => $membershipPlan]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());
        $validated['is_active'] = $validated['is_active'] ?? true;

        $plan = MembershipPlan::create($validated);

        return response()->json([
            'message' => 'Membership plan created successfully.',
            'plan' => $plan,
        ], Response::HTTP_CREATED);
    }

    public function update(Request $request, MembershipPlan $membershipPlan): JsonResponse
    {
        $membershipPlan->update($request->validate(
            $this->rules($membershipPlan, $request->isMethod('patch'))
        ));

        return response()->json([
            'message' => 'Membership plan updated successfully.',
            'plan' => $membershipPlan->fresh(),
        ]);
    }

    public function destroy(MembershipPlan $membershipPlan): JsonResponse
    {
        if ($membershipPlan->is_active) {
            $membershipPlan->update(['is_active' => false]);
        }

        return response()->json([
            'message' => 'Membership plan archived successfully.',
        ]);
    }

    private function rules(?MembershipPlan $membershipPlan = null, bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return [
            'name' => [
                $presence,
                'string',
                'max:255',
                Rule::unique('membership_plans', 'name')->ignore($membershipPlan?->id),
            ],
            'duration_days' => [$presence, 'integer', 'min:1'],
            'price' => [$presence, 'numeric', 'min:0', 'max:9999999999.99'],
            'plan_type' => [$presence, Rule::in(['membership', 'class'])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
