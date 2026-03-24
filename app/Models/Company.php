<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        'website',
        'logo',
        'db_host',
        'db_name',
        'db_username',
        'db_password',
        'db_port',
        'ai_provider',
        'ai_api_key',
        'plan'
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }
    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }
    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function activeSubscription()
    {
        return $this->hasOne(Subscription::class)
            ->where('status', 'active')
            ->latest();
    }
}
