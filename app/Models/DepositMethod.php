<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A way for a business to add funds (card link, crypto, bank, email request),
 * configured by Super Admin.
 */
class DepositMethod extends Model
{
    public const KEYS = ['card', 'crypto', 'bank', 'email'];

    /** Detail fields each method may carry (shown to the business). */
    public const DETAIL_FIELDS = [
        'card' => ['payment_link', 'provider'],
        'crypto' => ['currency', 'network', 'wallet_address'],
        'bank' => ['bank_name', 'account_name', 'account_number', 'iban', 'swift', 'branch', 'country'],
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
