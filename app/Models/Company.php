<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use SoftDeletes;
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
        'status',
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