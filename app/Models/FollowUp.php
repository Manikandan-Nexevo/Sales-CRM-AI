<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FollowUp extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'contact_id', 'call_log_id', 'type', 'subject',
        'message', 'scheduled_at', 'completed_at', 'status',
        'ai_generated', 'email_sent', 'whatsapp_sent'
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'completed_at' => 'datetime',
        'ai_generated' => 'boolean',
        'email_sent' => 'boolean',
        'whatsapp_sent' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function callLog()
    {
        return $this->belongsTo(CallLog::class);
    }

    public function scopeDueToday($query)
    {
        return $query->whereDate('scheduled_at', today())
                     ->where('status', 'pending');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}
