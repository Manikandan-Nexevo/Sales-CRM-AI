<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $fillable = [
        'company_id',
        'plan_id',
        'status',
        'start_date',
        'end_date',
        'trial_end_date',
        'payment_method',
        'amount_paid',
        'invoice_id',
        'auto_renew',
    ];

    protected $casts = [
        'auto_renew'     => 'boolean',
        'start_date'     => 'date',
        'end_date'       => 'date',
        'trial_end_date' => 'date',
        'amount_paid'    => 'float',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }
    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
