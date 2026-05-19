<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Business_suite extends Model
{
    protected $table = 'business_suite';

    protected $fillable = [
        'user_id',
        'sales_crm',
        'project_managment_tool',
        'status',
        'created_at',
        'updated_at'
    ];
}
