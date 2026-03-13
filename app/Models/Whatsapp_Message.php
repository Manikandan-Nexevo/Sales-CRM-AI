<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Whatsapp_Message extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'name',
        'phone',
        'message',
        'direction',
        'media_path',
        'message_type',
        'created_at',
        'updated_at'
    ];
}
