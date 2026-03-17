<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\TenantModel;

class CallLog extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'contact_id',
        'direction',
        'duration',
        'status',
        'outcome',
        'notes',
        'ai_summary',
        'voice_transcript',
        'next_action',
        'next_action_date',
        'call_recording_url',
        'sentiment',
        'interest_level',
        'scheduled_at',
        'answered_at',
        'ended_at'
    ];

    protected $casts = [
        'duration' => 'integer',
        'next_action_date' => 'datetime',
        'scheduled_at' => 'datetime',
        'answered_at' => 'datetime',
        'ended_at' => 'datetime',
        'interest_level' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function getDurationFormattedAttribute(): string
    {
        $minutes = floor($this->duration / 60);
        $seconds = $this->duration % 60;
        return "{$minutes}m {$seconds}s";
    }

    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }

    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}
