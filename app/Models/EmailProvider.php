<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailProvider extends Model
{
    public const DRIVERS = ['smtp', 'brevo', 'sendgrid', 'mailgun', 'ses', 'log'];

    protected $fillable = [
        'name', 'driver', 'host', 'port', 'username', 'secret', 'encryption', 'region',
        'from_email', 'from_name', 'is_active', 'status', 'last_tested_at', 'last_test_message',
    ];

    protected $hidden = ['secret'];

    protected $appends = ['has_secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'is_active' => 'boolean',
            'last_tested_at' => 'datetime',
        ];
    }

    public function getHasSecretAttribute(): bool
    {
        return !empty($this->attributes['secret']);
    }
}
