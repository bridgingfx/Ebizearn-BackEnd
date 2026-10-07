<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A way for a business to add funds (card link, bank, crypto, email request),
 * configured by Super Admin.
 *
 * Crypto deposits are supported (owner decision 2026-10-07): the 'crypto'
 * method (USDT) is toggled on/off like any other method, with the network
 * + wallet address configured in its details.
 */
class DepositMethod extends Model
{
    public const KEYS = ['card', 'bank', 'crypto', 'email'];

    /** Detail fields each method may carry (shown to the business). */
    public const DETAIL_FIELDS = [
        'card' => ['payment_link', 'provider'],
        'bank' => ['bank_name', 'account_name', 'account_number', 'iban', 'swift', 'branch', 'country'],
        'crypto' => ['network', 'wallet_address'],
        'email' => ['contact_email'],
    ];

    protected $fillable = ['key', 'title', 'is_active', 'instructions', 'details', 'min_amount_cents', 'max_amount_cents', 'sort_order', 'payment_gateway_id'];

    protected $casts = [
        'is_active' => 'boolean',
        'details' => 'array',
        'min_amount_cents' => 'integer',
        'max_amount_cents' => 'integer',
        'sort_order' => 'integer',
    ];

    public function gateway()
    {
        return $this->belongsTo(PaymentGateway::class, 'payment_gateway_id');
    }

    /**
     * True when this method can take payment automatically (active gateway
     * with credentials and an automatic driver).
     */
    public function isAutomatic(): bool
    {
        $gateway = $this->gateway;

        return $gateway !== null
            && $gateway->is_active
            && $gateway->has_credentials
            && in_array($gateway->driver, ['stripe'], true);
    }
}
