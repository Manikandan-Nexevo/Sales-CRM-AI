<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'company', 'designation', 'email', 'phone', 'phone_alt',
        'linkedin_url', 'linkedin_connected', 'website', 'industry',
        'company_size', 'location', 'source', 'status', 'priority',
        'assigned_to', 'notes', 'ai_score', 'ai_analysis', 'tags',
        'last_contacted_at', 'next_followup_at'
    ];

    protected $casts = [
        'linkedin_connected' => 'boolean',
        'tags' => 'array',
        'ai_analysis' => 'array',
        'ai_score' => 'integer',
        'last_contacted_at' => 'datetime',
        'next_followup_at' => 'datetime',
    ];

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function callLogs()
    {
        return $this->hasMany(CallLog::class);
    }

    public function followUps()
    {
        return $this->hasMany(FollowUp::class);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeSearch($query, $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('name', 'LIKE', "%{$term}%")
              ->orWhere('company', 'LIKE', "%{$term}%")
              ->orWhere('email', 'LIKE', "%{$term}%")
              ->orWhere('phone', 'LIKE', "%{$term}%");
        });
    }
}
