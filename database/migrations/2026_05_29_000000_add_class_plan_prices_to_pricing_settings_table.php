<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('pricing_settings', 'class_three_month_price')) {
                $table->decimal('class_three_month_price', 10, 2)->default(200000)->after('class_subscription_price');
            }

            if (!Schema::hasColumn('pricing_settings', 'class_six_month_price')) {
                $table->decimal('class_six_month_price', 10, 2)->default(380000)->after('class_three_month_price');
            }

            if (!Schema::hasColumn('pricing_settings', 'class_twelve_month_price')) {
                $table->decimal('class_twelve_month_price', 10, 2)->default(700000)->after('class_six_month_price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pricing_settings', function (Blueprint $table) {
            $drop = [];

            if (Schema::hasColumn('pricing_settings', 'class_three_month_price')) {
                $drop[] = 'class_three_month_price';
            }
            if (Schema::hasColumn('pricing_settings', 'class_six_month_price')) {
                $drop[] = 'class_six_month_price';
            }
            if (Schema::hasColumn('pricing_settings', 'class_twelve_month_price')) {
                $drop[] = 'class_twelve_month_price';
            }

            if (!empty($drop)) {
                $table->dropColumn($drop);
            }
        });
    }
};
