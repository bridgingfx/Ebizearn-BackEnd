<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentLog extends Model
{
    protected $fillable = [
        'event_key',
        'payment_gateway_id',
        'gateway_name',
        'direction',
        'amount_cents',
        'currency',
        'status',
        'reference_type',
        'reference_id',
        'provider_transaction_id',
        'message',
        'metadata_json',
    ];

    // NOTE: Laravel 10.50 does not support the model `casts()` method form
    // (Laravel 11+ only), so casts are declared as a property. Without this,
    // array metadata on payment logs crashes the log-only payout path.
    protected $casts = [
        'metadata_json' => 'array',
    ];
}
