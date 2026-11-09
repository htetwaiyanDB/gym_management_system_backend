<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MembershipPlan extends Model
{
    protected $fillable = [
        'name',
        'duration_days',
        'price',
        'plan_type',
        'is_active',
    ];

    protected $casts = [
        'duration_days' => 'integer',
        'price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function memberMemberships(): HasMany
    {
        return $this->hasMany(MemberMembership::class);
    }
}
