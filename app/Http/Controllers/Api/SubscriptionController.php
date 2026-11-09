<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MemberMembership;
use App\Models\MembershipPlan;
use App\Models\PricingSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;


class SubscriptionController extends Controller
{

    private const CLASS_PLAN_NAME = 'Class';
    private const ANNUAL_PLAN_MIN_DAYS = 360;
    private const SIX_MONTH_PLAN_MIN_DAYS = 180;
    private const THREE_MONTH_PLAN_MIN_DAYS = 90;
    private const MONTHLY_PLAN_MIN_DAYS = 30;


    public function index(Request $request)
    {
        // If you already protect routes with middleware, you can remove this.
        // abort_unless(auth()->check() && auth()->user()->role === 'administrator', 403);

        $subscriptions = MemberMembership::with(['member', 'plan'])
            ->orderByDesc('id')
            ->get();

        $pricingSetting = $this->pricingSetting();

        $today = Carbon::today();

        return response()->json([
            'subscriptions' => $subscriptions->map(function (MemberMembership $subscription) use ($pricingSetting, $today) {
                $durationDays = $subscription->plan?->duration_days ?? 0;
                $price = $subscription->plan
                    ? $this->resolvePlanPrice($subscription->plan, $pricingSetting)
                    : null;
                $discountPercentage = $subscription->discount_percentage !== null
                    ? (float) $subscription->discount_percentage
                    : null;
                $storedFinalPrice = $subscription->final_price !== null
                    ? (float) $subscription->final_price
                    : null;

                $finalPrice = $storedFinalPrice;

                if (
                    ($finalPrice === null || $finalPrice <= 0)
                    && ($discountPercentage === null || $discountPercentage <= 0)
                    && $price !== null
                ) {
                    $finalPrice = (float) $price;
                }

                $holdDays = 0;
                $adjustedEndDate = $subscription->end_date;

                if ($subscription->is_on_hold && $subscription->hold_started_at && $subscription->end_date) {
                    $holdStartedAt = Carbon::parse($subscription->hold_started_at);
                    $holdDays = max(0, $holdStartedAt->diffInDays($today));
                    $adjustedEndDate = Carbon::parse($subscription->end_date)->addDays($holdDays);
                }

                $isExpired = ! $subscription->is_on_hold
                    && ($subscription->is_expired || ($adjustedEndDate && $adjustedEndDate->lt($today)));
                $status = $subscription->is_on_hold ? 'On Hold' : ($isExpired ? 'Expired' : 'Active');

                return [
                    'id' => $subscription->id,
                    'member_name' => $subscription->member?->name ?? 'Unknown',
                    'member_phone' => $subscription->member?->phone,
                    'plan_name' => $subscription->plan?->name ?? 'Plan',
                    'duration_days' => $durationDays,
                    'price' => $price,
                    'discount_percentage' => $discountPercentage,
                    'final_price' => $finalPrice ?? 0,
                    'created_at' => optional($subscription->created_at)->toIso8601String(),
                    'start_date' => optional($subscription->start_date)->toDateString(),
                    'end_date' => optional($adjustedEndDate)->toDateString(),
                    'is_on_hold' => (bool) $subscription->is_on_hold,
                    'status' => $status,
                ];
            }),
        ]);
    }

    public function options()
    {
        $members = User::query()
            ->where('role', 'user')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'phone']);

        $pricingSetting = $this->pricingSetting();
        $plans = MembershipPlan::query()
            ->where('is_active', true)
            ->orderBy('plan_type')
            ->orderBy('duration_days')
            ->orderBy('id')
            ->get()
            ->map(fn (MembershipPlan $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'duration_days' => $plan->duration_days,
                'price' => $this->resolvePlanPrice($plan, $pricingSetting),
                'plan_type' => $plan->plan_type,
                'is_active' => $plan->is_active,
            ])
            ->values();

        return response()->json([
            'members' => $members,
            'plans' => $plans,
        ]);

    }

    public function store(Request $request)
    {
        $data = $request->validate([
        'member_id' => [
        'required',
            Rule::exists('users', 'id')->where(fn ($q) => $q->where('role', 'user')),
            ],
            'membership_plan_id' => ['required', 'exists:membership_plans,id'],
            'start_date' => ['nullable', 'date'],
            'discount_percentage' => ['nullable', 'numeric', 'between:0,100'],
        ]);

        $plan = MembershipPlan::query()->findOrFail($data['membership_plan_id']);

        if (! $plan->is_active) {
            throw ValidationException::withMessages([
                'membership_plan_id' => ['The selected membership plan is inactive and cannot be used for a new subscription.'],
            ]);
        }

        $startDate = isset($data['start_date'])
            ? Carbon::parse($data['start_date'])
            : Carbon::today();

        $endDate = ($plan->duration_days ?? 0) > 0
            ? $startDate->copy()->addDays((int) $plan->duration_days)
            : $startDate->copy();

        $pricingSetting = $this->pricingSetting();

        $price = $this->resolvePlanPrice($plan, $pricingSetting) ?? 0;
        $hasDiscount = array_key_exists('discount_percentage', $data)
            && $data['discount_percentage'] !== null
            && $data['discount_percentage'] !== '';

        $discountPercentage = $hasDiscount
            ? (float) $data['discount_percentage']
            : null;

        $finalPrice = $hasDiscount
            ? $price - ($price * ($discountPercentage / 100))
            : $price;

        $subscription = MemberMembership::create([
            'member_id' => $data['member_id'], // ✅ user id
            'membership_plan_id' => $plan->id,
            'discount_percentage' => $discountPercentage !== null ? round($discountPercentage, 2) : null,
            'final_price' => round($finalPrice, 2),
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'is_expired' => false,
            'is_on_hold' => false,
            'hold_started_at' => null,
        ]);

        return response()->json([
            'message' => 'Subscription created successfully.',
            'subscription_id' => $subscription->id,
        ], Response::HTTP_CREATED);
    }

    public function hold(MemberMembership $subscription)
    {
        $today = Carbon::today();

        if ($subscription->is_expired || ($subscription->end_date && $subscription->end_date->lt($today))) {
            return response()->json([
                'message' => 'Expired subscriptions cannot be placed on hold.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($subscription->is_on_hold) {
            return response()->json([
                'message' => 'Subscription is already on hold.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $subscription->update([
            'is_on_hold' => true,
            'hold_started_at' => $today,
        ]);

        return response()->json([
            'message' => 'Subscription placed on hold.',
        ]);
    }

    public function resume(MemberMembership $subscription)
    {
        if (! $subscription->is_on_hold) {
            return response()->json([
                'message' => 'Subscription is not on hold.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $today = Carbon::today();
        $holdStartedAt = $subscription->hold_started_at ? Carbon::parse($subscription->hold_started_at) : $today;
        $holdDays = max(0, $holdStartedAt->diffInDays($today));

        if ($subscription->end_date) {
            $subscription->end_date = Carbon::parse($subscription->end_date)->addDays($holdDays);
        }

        $subscription->is_on_hold = false;
        $subscription->hold_started_at = null;
        $subscription->is_expired = false;
        $subscription->save();

        return response()->json([
            'message' => 'Subscription resumed.',
        ]);
    }

    public function holdAll()
    {
        $today = Carbon::today();

        $updatedCount = MemberMembership::query()
            ->where('is_on_hold', false)
            ->where(function ($query) use ($today) {
                $query->where('is_expired', false)
                    ->orWhereNull('is_expired');
            })
            ->where(function ($query) use ($today) {
                $query->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $today->toDateString());
            })
            ->update([
                'is_on_hold' => true,
                'hold_started_at' => $today,
            ]);

        return response()->json([
            'message' => $updatedCount > 0
                ? 'All active subscriptions have been placed on hold.'
                : 'No active subscriptions were available to place on hold.',
            'updated_count' => $updatedCount,
        ]);
    }

        public function resumeAll()
    {
        $today = Carbon::today();

        $subscriptions = MemberMembership::query()
            ->where('is_on_hold', true)
            ->get();

        $updatedCount = 0;

        foreach ($subscriptions as $subscription) {
            $holdStartedAt = $subscription->hold_started_at
                ? Carbon::parse($subscription->hold_started_at)
                : $today;
            $holdDays = max(0, $holdStartedAt->diffInDays($today));

            if ($subscription->end_date) {
                $subscription->end_date = Carbon::parse($subscription->end_date)->addDays($holdDays);
            }

            $subscription->is_on_hold = false;
            $subscription->hold_started_at = null;
            $subscription->is_expired = false;
            $subscription->save();

            $updatedCount++;
        }

        return response()->json([
            'message' => $updatedCount > 0
                ? 'All on-hold subscriptions have been resumed.'
                : 'No on-hold subscriptions were available to resume.',
            'updated_count' => $updatedCount,
        ]);
    }


    public function extend(Request $request, MemberMembership $subscription)
    {
        $validated = $request->validate([
            'extension_days' => ['nullable', 'integer', 'min:1', 'required_without:new_end_date'],
            'new_end_date' => ['nullable', 'date', 'required_without:extension_days'],
        ]);

        $currentEndDate = $subscription->end_date
            ? Carbon::parse($subscription->end_date)
            : Carbon::today();

        if (array_key_exists('new_end_date', $validated) && $validated['new_end_date'] !== null) {
            $updatedEndDate = Carbon::parse($validated['new_end_date']);

            if ($updatedEndDate->lte($currentEndDate)) {
                return response()->json([
                    'message' => 'New end date must be after the current end date.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        } else {
            $updatedEndDate = $currentEndDate->copy()->addDays((int) $validated['extension_days']);
        }

        $subscription->update([
            'end_date' => $updatedEndDate->toDateString(),
            'is_expired' => false,
        ]);

        return response()->json([
            'message' => 'Subscription end date extended successfully.',
            'end_date' => $subscription->end_date?->toDateString(),
        ]);
    }

    public function destroy(MemberMembership $subscription)
    {
        $subscription->delete();

        return response()->json([
            'message' => 'Subscription deleted successfully.',
        ]);
    }


    private function resolvePlanPrice(MembershipPlan $plan, ?PricingSetting $pricingSetting): ?float
    {
        if ($plan->price !== null) {
            return (float) $plan->price;
        }

        $durationDays = $plan->duration_days;

        if (! $pricingSetting || ! $durationDays) {
            return null;
        }

        if ($plan->plan_type === 'class' || $plan->name === self::CLASS_PLAN_NAME) {
            if ($durationDays >= self::ANNUAL_PLAN_MIN_DAYS && $pricingSetting->class_twelve_month_price !== null) {
                return (float) $pricingSetting->class_twelve_month_price;
            }

            if ($durationDays >= self::SIX_MONTH_PLAN_MIN_DAYS && $pricingSetting->class_six_month_price !== null) {
                return (float) $pricingSetting->class_six_month_price;
            }

            if ($durationDays >= self::THREE_MONTH_PLAN_MIN_DAYS && $pricingSetting->class_three_month_price !== null) {
                return (float) $pricingSetting->class_three_month_price;
            }

            return (float) $pricingSetting->class_subscription_price;
        }


        if ($durationDays >= self::ANNUAL_PLAN_MIN_DAYS) {
            return (float) $pricingSetting->annual_subscription_price;
        }

        if ($durationDays >= self::SIX_MONTH_PLAN_MIN_DAYS) {
            return (float) $pricingSetting->quarterly_subscription_price;
        }

        if ($durationDays >= self::THREE_MONTH_PLAN_MIN_DAYS) {
            return (float) $pricingSetting->three_month_subscription_price;
        }


        if ($durationDays >= self::MONTHLY_PLAN_MIN_DAYS) {
            return (float) $pricingSetting->monthly_subscription_price;
        }

        return null;
    }

    private function pricingSetting(): PricingSetting
    {
        return PricingSetting::query()->first() ?? new PricingSetting([
            'class_subscription_price' => 70000,
            'monthly_subscription_price' => 80000,
            'three_month_subscription_price' => 240000,
            'quarterly_subscription_price' => 400000,
            'annual_subscription_price' => 960000,
            'class_three_month_price' => 200000,
            'class_six_month_price' => 380000,
            'class_twelve_month_price' => 700000,
        ]);
    }
}
