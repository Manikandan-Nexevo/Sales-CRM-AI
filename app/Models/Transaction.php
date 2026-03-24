<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'company_id',
        'invoice_id',
        'transaction_id',
        'type',
        'method',
        'amount',
        'status',
        'description',
        'gateway_response',
    ];

    protected $casts = [
        'amount'           => 'float',
        'gateway_response' => 'array',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
