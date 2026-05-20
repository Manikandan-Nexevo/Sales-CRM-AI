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
        'company_id', // ✅ VERY IMPORTANT
        'permissions',
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
        'permissions'          => 'array',
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

    public function businessSuite()
    {
        return $this->hasOne(Business_suite::class);
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
        return $this->normalized_role === 'admin';
    }

    public function todayCallCount(): int
    {
        return $this->callLogs()
            ->whereDate('created_at', today())
            ->count();
    }

    public function sidebarItems(): array
    {
        if (in_array($this->normalized_role, ['admin', 'superadmin'])) {
            return ['contacts', 'calls', 'followups', 'team', 'availability', 'bookings', 'settings'];
        }

        $permissions = $this->permissions ?? [];

        $map = [
            'contacts'     => 'sidebar.contacts',
            'calls'        => 'sidebar.calls',
            'followups'    => 'sidebar.followups',
            'team'         => 'sidebar.team',
            'availability' => 'sidebar.availability',
            'bookings'     => 'sidebar.bookings',
            'settings'     => 'sidebar.settings',
        ];

        return array_keys(array_filter(
            $map,
            fn($permission) => in_array($permission, $permissions)
        ));
    }

    public function syncPermissions(array $permissions): void
    {
        $this->update(['permissions' => array_values(array_unique($permissions))]);
    }

    public function getNormalizedRoleAttribute()
    {
        $value = $this->attributes['role'] ?? null;
        if (empty($value)) {
            return $value;
        }

        if (strpos($value, '{') === 0) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $role = $decoded['sales_crm'] ?? $decoded['project_management_tool'] ?? reset($decoded);
                if ($role === 'master-superadmin') {
                    return 'superadmin';
                }
                return $role;
            }
        }

        if ($value === 'master-superadmin') {
            return 'superadmin';
        }

        return $value;
    }

    public function setRoleAttribute($value)
    {
        if (empty($value)) {
            $this->attributes['role'] = $value;
            return;
        }

        $existing = [];
        $currentValue = $this->attributes['role'] ?? null;
        if (!empty($currentValue) && strpos($currentValue, '{') === 0) {
            $decoded = json_decode($currentValue, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $existing = $decoded;
            }
        }

        if (is_array($value)) {
            $this->attributes['role'] = json_encode(array_merge($existing, $value));
            return;
        }

        if (is_string($value) && strpos($value, '{') === 0) {
            $decodedInput = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decodedInput)) {
                $this->attributes['role'] = json_encode(array_merge($existing, $decodedInput));
                return;
            }
            $this->attributes['role'] = $value;
            return;
        }

        $existing['sales_crm'] = $value;
        $this->attributes['role'] = json_encode($existing);
    }

    public function scopeHasRole($query, $role)
    {
        $rolesToMatch = [$role];
        if ($role === 'superadmin') {
            $rolesToMatch[] = 'master-superadmin';
        }

        return $query->where(function($q) use ($rolesToMatch) {
            foreach ($rolesToMatch as $r) {
                $q->orWhere('role', $r)
                  ->orWhere('role', 'like', '%"sales_crm":"' . $r . '"%')
                  ->orWhere('role', 'like', '%"sales_crm": "' . $r . '"%');
            }
        });
    }

    public function scopeNotRole($query, $role)
    {
        $rolesToMatch = [$role];
        if ($role === 'superadmin') {
            $rolesToMatch[] = 'master-superadmin';
        }

        return $query->where(function($q) use ($rolesToMatch) {
            foreach ($rolesToMatch as $r) {
                $q->where('role', '!=', $r)
                  ->where('role', 'not like', '%"sales_crm":"' . $r . '"%')
                  ->where('role', 'not like', '%"sales_crm": "' . $r . '"%');
            }
        });
    }
}
