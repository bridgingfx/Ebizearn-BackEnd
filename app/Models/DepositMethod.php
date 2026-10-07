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

    protected $fillable = ['key', 'title', 'is_active', 'instructions', 'details', 'min_amount_cents', 'max_amount_cents', 'sort_order'];

    protected $casts = [
        'is_active' => 'boolean',
        'details' => 'array',
        'min_amount_cents' => 'integer',
        'max_amount_cents' => 'integer',
        'sort_order' => 'integer',
    ];
}
