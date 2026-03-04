<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'role', 'phone',
        'avatar', 'target_calls_daily', 'target_leads_monthly', 'is_active'
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
        'target_calls_daily' => 'integer',
        'target_leads_monthly' => 'integer',
    ];

    public function callLogs()
    {
        return $this->hasMany(CallLog::class);
    }

    public function contacts()
    {
        return $this->hasMany(Contact::class, 'assigned_to');
    }

    public function followUps()
    {
        return $this->hasMany(FollowUp::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function todayCallCount(): int
    {
        return $this->callLogs()->whereDate('created_at', today())->count();
    }
}
