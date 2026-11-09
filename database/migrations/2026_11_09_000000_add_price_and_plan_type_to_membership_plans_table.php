<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('membership_plans', 'price')) {
            Schema::table('membership_plans', function (Blueprint $table) {
                $table->decimal('price', 12, 2)->nullable()->after('duration_days');
            });
        }

        if (! Schema::hasColumn('membership_plans', 'plan_type')) {
            Schema::table('membership_plans', function (Blueprint $table) {
                $table->string('plan_type')->default('membership')->after('price');
            });
        }

        DB::table('membership_plans')
            ->whereNull('plan_type')
            ->update(['plan_type' => 'membership']);

        DB::table('membership_plans')
            ->whereIn(DB::raw('LOWER(name)'), [
                'class',
                'class 3 months',
                '3 months class',
                'class 6 months',
                '6 months class',
                'class 12 months',
                '12 months class',
            ])
            ->update(['plan_type' => 'class']);

        $pricing = Schema::hasTable('pricing_settings')
            ? DB::table('pricing_settings')->first()
            : null;

        if (! $pricing) {
            return;
        }

        DB::table('membership_plans')
            ->whereNull('price')
            ->orderBy('id')
            ->chunkById(100, function ($plans) use ($pricing) {
                foreach ($plans as $plan) {
                    $price = $this->legacyPrice($plan, $pricing);

                    if ($price !== null) {
                        DB::table('membership_plans')
                            ->where('id', $plan->id)
                            ->whereNull('price')
                            ->update(['price' => $price]);
                    }
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('membership_plans', 'plan_type')) {
            Schema::table('membership_plans', function (Blueprint $table) {
                $table->dropColumn('plan_type');
            });
        }

        if (Schema::hasColumn('membership_plans', 'price')) {
            Schema::table('membership_plans', function (Blueprint $table) {
                $table->dropColumn('price');
            });
        }
    }

    private function legacyPrice(object $plan, object $pricing): mixed
    {
        $name = strtolower(trim((string) $plan->name));
        $days = (int) $plan->duration_days;

        if (($plan->plan_type ?? null) === 'class') {
            if ($days >= 360 && $days <= 365) {
                return $pricing->class_twelve_month_price
                    ?? $pricing->class_subscription_price
                    ?? null;
            }

            if ($days >= 175 && $days <= 185) {
                return $pricing->class_six_month_price
                    ?? $pricing->class_subscription_price
                    ?? null;
            }

            if ($days >= 85 && $days <= 95) {
                return $pricing->class_three_month_price
                    ?? $pricing->class_subscription_price
                    ?? null;
            }

            return $pricing->class_subscription_price ?? null;
        }

        if ($name === '12 months' || ($days >= 360 && $days <= 365)) {
            return $pricing->annual_subscription_price ?? null;
        }

        if ($name === '6 months' || ($days >= 175 && $days <= 185)) {
            return $pricing->quarterly_subscription_price ?? null;
        }

        if ($name === '3 months' || ($days >= 85 && $days <= 95)) {
            return $pricing->three_month_subscription_price ?? null;
        }

        if ($name === '1 month' || ($days >= 28 && $days <= 31)) {
            return $pricing->monthly_subscription_price ?? null;
        }

        return null;
    }
};
