<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $fillable = [
        'name',
        'db_host',
        'db_name',
        'db_username',
        'db_password',
        'db_port',
        'ai_provider',
        'ai_api_key',
        'plan'
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
