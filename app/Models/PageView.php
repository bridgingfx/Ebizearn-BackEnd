<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PageView extends Model
{
    protected $fillable = [
        'session_id',
        'user_id',
        'path',
        'referrer',
        'user_agent',
        'ip_address',
        'country_code',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
