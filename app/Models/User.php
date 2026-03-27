<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\Company;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    // ✅ IMPORTANT: Keep user in MAIN DB (do NOT set tenant connection here)
    protected $connection = 'mysql';
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'phone',

        'avatar',
        'target_calls_daily',
        'target_leads_monthly',
        'is_active',
        'company_id' // ✅ VERY IMPORTANT
    ];

    protected $hidden = [
        'password',
        'remember_token'
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
        'target_calls_daily' => 'integer',
        'target_leads_monthly' => 'integer',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELATIONSHIPS
    |--------------------------------------------------------------------------
    */

    // ✅ Link user → company (used for tenant DB switching)
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    // These models should extend TenantModel (tenant DB)
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

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function todayCallCount(): int
    {
        return $this->callLogs()
            ->whereDate('created_at', today())
            ->count();
    }
}
