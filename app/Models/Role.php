<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $fillable = ['name', 'label', 'description', 'permissions', 'color'];
    protected $casts    = ['permissions' => 'array'];

    public function users()
    {
        return $this->hasMany(User::class, 'role', 'name');
    }
}
