<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricingSetting extends Model
{
    protected $fillable = [
        'monthly_subscription_price',
        'three_month_subscription_price',
        'quarterly_subscription_price',
        'annual_subscription_price',
        'class_subscription_price',
        'class_three_month_price',
        'class_six_month_price',
        'class_twelve_month_price',
    ];
}
