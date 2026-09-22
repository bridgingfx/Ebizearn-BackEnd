<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentGateway extends Model
{
    public const DRIVERS = ['bank_transfer', 'paypal', 'wise', 'crypto', 'stripe', 'log'];

    protected $fillable = [
        'name', 'driver', 'display_name', 'credentials', 'is_active', 'status',
        'last_tested_at', 'last_test_message',
    ];

    protected $hidden = ['credentials'];

    protected $appends = ['has_credentials'];

    // NOTE: Laravel 10.50 does not support the model `casts()` method form
    // (Laravel 11+ only), so casts are declared as a property.
    protected $casts = [
        'credentials' => 'encrypted:array',
        'is_active' => 'boolean',
        'last_tested_at' => 'datetime',
    ];

    public function getHasCredentialsAttribute(): bool
    {
        return !empty($this->attributes['credentials']);
    }
}
