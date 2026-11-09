<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PricingSetting;
use App\Models\MembershipPlan;
use App\Models\TrainerPricing;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PricingController extends Controller
{
    public function index(): JsonResponse
    {
        $pricingSetting = PricingSetting::firstOrCreate(
            [],
            [
                'monthly_subscription_price' => 100000,
                'three_month_subscription_price' => 240000,
                'quarterly_subscription_price' => 400000,
                'annual_subscription_price' => 960000,
                'class_subscription_price' => 70000,
                'class_three_month_price' => 200000,
                'class_six_month_price' => 380000,
                'class_twelve_month_price' => 700000,
            ]
        );

        $trainers = User::where('role', 'trainer')
            ->select('id', 'name')
            ->orderBy('name')
            ->get()
            ->map(function ($t) {
                $tp = TrainerPricing::where('trainer_id', $t->id)->first();
                return [
                    'id' => $t->id,
                    'name' => $t->name,
                    'price_per_session' => $tp?->price_per_session,
                ];
            });

        return response()->json([
            'subscription_prices' => [
                'one_month' => $pricingSetting->monthly_subscription_price,
                'three_months' => $pricingSetting->three_month_subscription_price,
                'six_months' => $pricingSetting->quarterly_subscription_price,
                'twelve_months' => $pricingSetting->annual_subscription_price,
                'class' => $pricingSetting->class_subscription_price,
            ],
            'class_prices' => [
                'one_month' => $pricingSetting->class_subscription_price,
                'three_months' => $pricingSetting->class_three_month_price,
                'six_months' => $pricingSetting->class_six_month_price,
                'twelve_months' => $pricingSetting->class_twelve_month_price,
            ],
            'trainers' => $trainers,
        ]);
    }


    public function updateTrainer(Request $request, User $user): JsonResponse
    {
        if ($user->role !== 'trainer') {
            abort(404);
        }

        $validated = $request->validate([
            'price_per_session' => ['required', 'numeric', 'min:0'],
        ]);

        $trainerPricing = TrainerPricing::updateOrCreate(
            ['trainer_id' => $user->id],
            ['price_per_session' => $validated['price_per_session']]
        );

        return response()->json([
            'message' => "Session price updated for {$user->name}.",
            'trainer' => [
                'id' => $user->id,
                'name' => $user->name,
            ],
            'pricing' => [
                'price_per_session' => $trainerPricing->price_per_session,
            ],
        ]);
    }

    /**
     * Update One-Month Subscription Price
     */
    public function updateOneMonth(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'one_month_subscription_price' => ['required', 'numeric', 'min:0'],
        ]);

        $pricingSetting = $this->updateSettingAndPlans(
            'monthly_subscription_price',
            $validated['one_month_subscription_price'],
            ['1 Month'],
        );

        return response()->json([
            'message' => '1-Month subscription price updated.',
            'pricing' => [
                'one_month_subscription_price' => $pricingSetting->monthly_subscription_price,
            ],
        ]);
    }

    /**
     * Update 3_Month Subscription Price
     */
    public function updateThreeMonths(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'three_month_subscription_price' => ['required', 'numeric', 'min:0'],
        ]);

        $pricingSetting = $this->updateSettingAndPlans(
            'three_month_subscription_price',
            $validated['three_month_subscription_price'],
            ['3 Months'],
        );

        return response()->json([
            'message' => '3-month subscription price updated.',
            'pricing' => [
                'three_month_subscription_price' => $pricingSetting->three_month_subscription_price,
            ],
        ]);
    }

    /**
     * Update 6-Momth Subscription Price
     */
    public function updateSixMonths(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'six_month_subscription_price' => ['required', 'numeric', 'min:0'],
        ]);

        $pricingSetting = $this->updateSettingAndPlans(
            'quarterly_subscription_price',
            $validated['six_month_subscription_price'],
            ['6 Months'],
        );

        return response()->json([
            'message' => '6-month subscription price updated.',
            'pricing' => [
                'six_month_subscription_price' => $pricingSetting->quarterly_subscription_price,
            ],
        ]);
    }

    /**
     * Update 12-Month Subscription Price
     */
    public function updateTwelveMonths(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'twelve_month_subscription_price' => ['required', 'numeric', 'min:0'],
        ]);

        $pricingSetting = $this->updateSettingAndPlans(
            'annual_subscription_price',
            $validated['twelve_month_subscription_price'],
            ['12 Months'],
        );

        return response()->json([
            'message' => '12-month subscription price updated.',
            'pricing' => [
                'twelve_month_subscription_price' => $pricingSetting->annual_subscription_price,
            ],
        ]);
    }

        /**
     * Update Class Subscription Price
     */
    public function updateClass(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'class_subscription_price' => ['required', 'numeric', 'min:0'],
        ]);

        $pricingSetting = $this->updateSettingAndPlans(
            'class_subscription_price',
            $validated['class_subscription_price'],
            ['Class'],
        );

        return response()->json([
            'message' => 'Class subscription price updated.',
            'pricing' => [
                'class_subscription_price' => $pricingSetting->class_subscription_price,
            ],
        ]);
    }

    public function updateClassThreeMonths(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
        ]);

        $pricingSetting = $this->updateSettingAndPlans(
            'class_three_month_price',
            $validated['price'],
            ['Class 3 Months', '3 Months Class'],
        );

        return response()->json([
            'message' => 'Class 3-month price updated.',
            'pricing' => [
                'class_three_month_price' => (float) $pricingSetting->class_three_month_price,
            ],
        ]);
    }

    public function updateClassSixMonths(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
        ]);

        $pricingSetting = $this->updateSettingAndPlans(
            'class_six_month_price',
            $validated['price'],
            ['Class 6 Months', '6 Months Class'],
        );

        return response()->json([
            'message' => 'Class 6-month price updated.',
            'pricing' => [
                'class_six_month_price' => (float) $pricingSetting->class_six_month_price,
            ],
        ]);
    }

    public function updateClassTwelveMonths(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
        ]);

        $pricingSetting = $this->updateSettingAndPlans(
            'class_twelve_month_price',
            $validated['price'],
            ['Class 12 Months', '12 Months Class'],
        );

        return response()->json([
            'message' => 'Class 12-month price updated.',
            'pricing' => [
                'class_twelve_month_price' => (float) $pricingSetting->class_twelve_month_price,
            ],
        ]);
    }

    private function updateSettingAndPlans(string $settingColumn, mixed $price, array $planNames): PricingSetting
    {
        return DB::transaction(function () use ($settingColumn, $price, $planNames) {
            $setting = PricingSetting::query()->lockForUpdate()->first();

            if (! $setting) {
                $setting = PricingSetting::create([
                    'monthly_subscription_price' => 80000,
                    'three_month_subscription_price' => 240000,
                    'quarterly_subscription_price' => 400000,
                    'annual_subscription_price' => 960000,
                    'class_subscription_price' => 70000,
                    'class_three_month_price' => 200000,
                    'class_six_month_price' => 380000,
                    'class_twelve_month_price' => 700000,
                ]);
            }

            $setting->update([$settingColumn => $price]);

            MembershipPlan::query()
                ->whereIn('name', $planNames)
                ->update(['price' => $price]);

            return $setting->fresh();
        });
    }
}
