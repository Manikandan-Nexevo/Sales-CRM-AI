<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = [
        'name',
        'description',
        'price',
        'billing_cycle',
        'max_users',
        'max_leads',
        'features',
        'is_active',
        'trial_days',
    ];

    protected $casts = [
        'features'  => 'array',
        'is_active' => 'boolean',
        'price'     => 'float',
    ];

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }
    public function activeSubscriptions()
    {
        return $this->hasMany(Subscription::class)->where('status', 'active');
    }
}
