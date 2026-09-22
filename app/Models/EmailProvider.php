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

    // NOTE: Laravel 10.50 does not support the model `casts()` method form
    // (Laravel 11+ only), so casts are declared as a property.
    protected $casts = [
        'secret' => 'encrypted',
        'is_active' => 'boolean',
        'last_tested_at' => 'datetime',
    ];

    public function getHasSecretAttribute(): bool
    {
        return !empty($this->attributes['secret']);
    }
}
