<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $fillable = ['name', 'label', 'description', 'permissions', 'color'];
    protected $casts    = ['permissions' => 'array'];
    protected $appends  = ['users_count'];

    public function getUsersCountAttribute()
    {
        return User::hasRole($this->name)->count();
    }
}
