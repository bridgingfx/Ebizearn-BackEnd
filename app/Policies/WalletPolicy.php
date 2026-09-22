<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Wallet;

/**
 * Phase 13: wallet privacy.
 *
 * Every wallet endpoint today is self-scoped (auth user only), so there is
 * no cross-user wallet read vector. This policy is registered as the
 * canonical guard for any current or future endpoint that resolves a wallet
 * by id (e.g. admin support views): only the wallet owner may view it.
 */
class WalletPolicy
{
    public function view(User $user, Wallet $wallet): bool
    {
        return (int) $user->id === (int) $wallet->user_id;
    }
}
