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

    protected function casts(): array
    {
        return [
            'metadata_json' => 'array',
        ];
    }
}
