<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $fillable = [
        'company_id',
        'plan_id',
        'subscription_id',
        'invoice_number',
        'amount',
        'status',
        'due_date',
        'paid_date',
        'period',
    ];

    protected $casts = [
        'amount'    => 'float',
        'due_date'  => 'date',
        'paid_date' => 'date',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }
    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}
