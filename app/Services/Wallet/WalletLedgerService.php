<?php

namespace App\Services\Wallet;

use App\Models\IdempotencyKey;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WithdrawalRequest;
use App\Models\WithdrawalRule;
use App\Services\Payment\PaymentService;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class WalletLedgerService
{
    /**
     * Credit a wallet with atomic transaction and balance update.
     */
    public function credit(
        Wallet $wallet,
        int $amountCents,
        string $type,
        string $description,
        ?string $refType = null,
        ?int $refId = null,
        ?array $metadata = null,
        ?string $idempotencyKey = null
    ): WalletTransaction {
        if ($amountCents <= 0) {
            throw new Exception('Credit amount must be positive.');
        }

        return $this->withIdempotency(
            $idempotencyKey,
            'wallet.credit',
            $wallet->user_id,
            ['wallet_id' => $wallet->id, 'amount_cents' => $amountCents, 'type' => $type, 'ref' => [$refType, $refId]],
            function () use ($wallet, $amountCents, $type, $description, $refType, $refId, $metadata) {
                return DB::transaction(function () use ($wallet, $amountCents, $type, $description, $refType, $refId, $metadata) {
                    $lockedWallet = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();

                    $newBalance = $lockedWallet->available_balance_cents + $amountCents;
                    $newLifetime = $lockedWallet->lifetime_earnings_cents + $amountCents;

                    $lockedWallet->update([
                        'available_balance_cents' => $newBalance,
                        'lifetime_earnings_cents' => $newLifetime,
                    ]);

                    return WalletTransaction::create([
                        'wallet_id' => $lockedWallet->id,
                        'type' => $type,
                        'amount_cents' => $amountCents,
                        'balance_after_cents' => $newBalance,
                        'currency' => $lockedWallet->currency,
                        'reference_type' => $refType,
                        'reference_id' => $refId,
                        'description' => $description,
                        'metadata_json' => $metadata,
                        'created_at' => now(),
                    ]);
                });
            }
        );
    }

    /**
     * Debit a wallet atomically ensuring non-negative balance.
     */
    public function debit(
        Wallet $wallet,
        int $amountCents,
        string $type,
        string $description,
        ?string $refType = null,
        ?int $refId = null,
        ?array $metadata = null,
        ?string $idempotencyKey = null
    ): WalletTransaction {
        if ($amountCents <= 0) {
            throw new Exception('Debit amount must be positive.');
        }

        return $this->withIdempotency(
            $idempotencyKey,
            'wallet.debit',
            $wallet->user_id,
            ['wallet_id' => $wallet->id, 'amount_cents' => $amountCents, 'type' => $type, 'ref' => [$refType, $refId]],
            function () use ($wallet, $amountCents, $type, $description, $refType, $refId, $metadata) {
                return DB::transaction(function () use ($wallet, $amountCents, $type, $description, $refType, $refId, $metadata) {
                    $lockedWallet = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();

                    if ($lockedWallet->available_balance_cents < $amountCents) {
                        throw new Exception('Insufficient wallet balance.');
                    }

                    $newBalance = $lockedWallet->available_balance_cents - $amountCents;

                    $lockedWallet->update([
                        'available_balance_cents' => $newBalance,
                    ]);

                    return WalletTransaction::create([
                        'wallet_id' => $lockedWallet->id,
                        'type' => $type,
                        'amount_cents' => -$amountCents, // Negative for ledger debit
                        'balance_after_cents' => $newBalance,
                        'currency' => $lockedWallet->currency,
                        'reference_type' => $refType,
                        'reference_id' => $refId,
                        'description' => $description,
                        'metadata_json' => $metadata,
                        'created_at' => now(),
                    ]);
                });
            }
        );
    }

    /**
     * Escrow hold: earmark funds by moving them from available to pending.
     * Used by the campaign funding gate — a campaign only goes active once the
     * business wallet covers its budget via this hold.
     */
    public function hold(
        Wallet $wallet,
        int $amountCents,
        string $type,
        string $description,
        ?string $refType = null,
        ?int $refId = null,
        ?array $metadata = null,
        ?string $idempotencyKey = null
    ): WalletTransaction {
        if ($amountCents <= 0) {
            throw new Exception('Hold amount must be positive.');
        }

        return $this->withIdempotency(
            $idempotencyKey,
            'wallet.hold',
            $wallet->user_id,
            ['wallet_id' => $wallet->id, 'amount_cents' => $amountCents, 'type' => $type, 'ref' => [$refType, $refId]],
            function () use ($wallet, $amountCents, $type, $description, $refType, $refId, $metadata) {
                return DB::transaction(function () use ($wallet, $amountCents, $type, $description, $refType, $refId, $metadata) {
                    $lockedWallet = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();

                    if ($lockedWallet->available_balance_cents < $amountCents) {
                        throw new Exception('Insufficient available balance for escrow hold.');
                    }

                    $lockedWallet->decrement('available_balance_cents', $amountCents);
                    $lockedWallet->increment('pending_balance_cents', $amountCents);

                    return WalletTransaction::create([
                        'wallet_id' => $lockedWallet->id,
                        'type' => $type,
                        'amount_cents' => -$amountCents,
                        'balance_after_cents' => $lockedWallet->available_balance_cents,
                        'currency' => $lockedWallet->currency,
                        'reference_type' => $refType,
                        'reference_id' => $refId,
                        'description' => $description,
                        'metadata_json' => array_merge($metadata ?? [], ['escrow_hold' => true]),
                        'created_at' => now(),
                    ]);
                });
            }
        );
    }

    /**
     * Release a previous escrow hold back to the available balance
     * (e.g. campaign cancelled with unspent budget).
     */
    public function releaseHold(
        Wallet $wallet,
        int $amountCents,
        string $type,
        string $description,
        ?string $refType = null,
        ?int $refId = null,
        ?array $metadata = null,
        ?string $idempotencyKey = null
    ): WalletTransaction {
        if ($amountCents <= 0) {
            throw new Exception('Release amount must be positive.');
        }

        return $this->withIdempotency(
            $idempotencyKey,
            'wallet.release_hold',
            $wallet->user_id,
            ['wallet_id' => $wallet->id, 'amount_cents' => $amountCents, 'type' => $type, 'ref' => [$refType, $refId]],
            function () use ($wallet, $amountCents, $type, $description, $refType, $refId, $metadata) {
                return DB::transaction(function () use ($wallet, $amountCents, $type, $description, $refType, $refId, $metadata) {
                    $lockedWallet = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();

                    if ($lockedWallet->pending_balance_cents < $amountCents) {
                        throw new Exception('Insufficient held balance to release.');
                    }

                    $lockedWallet->decrement('pending_balance_cents', $amountCents);
                    $lockedWallet->increment('available_balance_cents', $amountCents);

                    return WalletTransaction::create([
                        'wallet_id' => $lockedWallet->id,
                        'type' => $type,
                        'amount_cents' => $amountCents,
                        'balance_after_cents' => $lockedWallet->available_balance_cents,
                        'currency' => $lockedWallet->currency,
                        'reference_type' => $refType,
                        'reference_id' => $refId,
                        'description' => $description,
                        'metadata_json' => array_merge($metadata ?? [], ['escrow_release' => true]),
                        'created_at' => now(),
                    ]);
                });
            }
        );
    }

    /**
     * Cancel an outstanding retention hold: the held reward never reached
     * the wallet owner, so it leaves pending (and lifetime earnings) without
     * touching available. Used when a retention-held task reward is reversed
     * before the retention period matured.
     *
     * Idempotent: a hold that was already released or cancelled returns the
     * existing release/cancel entry, so a retried reversal can never
     * double-unwind.
     */
    public function cancelRetentionHold(Wallet $wallet, WalletTransaction $holdTx, string $reason): WalletTransaction
    {
        if ($holdTx->type !== 'retention_hold') {
            throw new Exception('Only retention_hold transactions can be cancelled.');
        }

        return $this->withIdempotency(
            "retention-cancel-{$holdTx->id}",
            'wallet.cancel_retention_hold',
            $wallet->user_id,
            ['wallet_id' => $wallet->id, 'hold_tx_id' => $holdTx->id],
            function () use ($wallet, $holdTx, $reason) {
                return DB::transaction(function () use ($wallet, $holdTx, $reason) {
                    $lockedWallet = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();

                    if ((int) $lockedWallet->id !== (int) $holdTx->wallet_id) {
                        throw new Exception('Hold wallet does not match.');
                    }

                    // Already released or cancelled: return the existing entry.
                    $existing = WalletTransaction::where('wallet_id', $lockedWallet->id)
                        ->whereIn('type', ['retention_release', 'retention_hold_cancel'])
                        ->where('metadata_json->hold_transaction_id', $holdTx->id)
                        ->first();

                    if ($existing) {
                        return $existing;
                    }

                    $cancel = min($lockedWallet->pending_balance_cents, abs((int) $holdTx->amount_cents));

                    $lockedWallet->decrement('pending_balance_cents', $cancel);
                    $lockedWallet->decrement(
                        'lifetime_earnings_cents',
                        min($lockedWallet->lifetime_earnings_cents, $cancel)
                    );

                    return WalletTransaction::create([
                        'wallet_id' => $lockedWallet->id,
                        'type' => 'retention_hold_cancel',
                        'amount_cents' => -$cancel,
                        'balance_after_cents' => $lockedWallet->available_balance_cents,
                        'currency' => $lockedWallet->currency,
                        'reference_type' => $holdTx->reference_type,
                        'reference_id' => $holdTx->reference_id,
                        'description' => "Cancelled retention hold #{$holdTx->id}: {$reason}",
                        'metadata_json' => [
                            'hold_transaction_id' => $holdTx->id,
                            'cancelled_amount_cents' => $cancel,
                        ],
                        'created_at' => now(),
                    ]);
                });
            }
        );
    }

    /**
     * Settle escrowed funds when a held reward is actually settled: reduces
     * the pending (held) balance without returning it to available — the money
     * leaves the business's custody and is credited to the contributor's wallet
     * by the caller in the same transaction.
     */
    public function settleEscrow(
        Wallet $wallet,
        int $amountCents,
        string $description,
        ?string $refType = null,
        ?int $refId = null,
        ?array $metadata = null
    ): WalletTransaction {
        if ($amountCents <= 0) {
            throw new Exception('Settlement amount must be positive.');
        }

        return DB::transaction(function () use ($wallet, $amountCents, $description, $refType, $refId, $metadata) {
            $lockedWallet = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();

            if ($lockedWallet->pending_balance_cents < $amountCents) {
                throw new Exception('Insufficient escrowed balance to settle.');
            }

            $lockedWallet->decrement('pending_balance_cents', $amountCents);

            return WalletTransaction::create([
                'wallet_id' => $lockedWallet->id,
                'type' => 'campaign_funding',
                'amount_cents' => -$amountCents,
                'balance_after_cents' => $lockedWallet->available_balance_cents,
                'currency' => $lockedWallet->currency,
                'reference_type' => $refType,
                'reference_id' => $refId,
                'description' => $description,
                'metadata_json' => array_merge($metadata ?? [], ['escrow_settlement' => true]),
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Restore previously settled escrow back into the pending hold — e.g. an
     * approval is reversed and the clawed-back funds return to the campaign's
     * escrow pool. Only ever increases the pending balance; a no-op for
     * non-positive amounts.
     */
    public function restoreEscrow(
        Wallet $wallet,
        int $amountCents,
        string $description,
        ?string $refType = null,
        ?int $refId = null,
        ?array $metadata = null
    ): ?WalletTransaction {
        if ($amountCents <= 0) {
            return null;
        }

        return DB::transaction(function () use ($wallet, $amountCents, $description, $refType, $refId, $metadata) {
            $lockedWallet = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();

            $lockedWallet->increment('pending_balance_cents', $amountCents);

            return WalletTransaction::create([
                'wallet_id' => $lockedWallet->id,
                'type' => 'campaign_funding',
                'amount_cents' => $amountCents,
                'balance_after_cents' => $lockedWallet->available_balance_cents,
                'currency' => $lockedWallet->currency,
                'reference_type' => $refType,
                'reference_id' => $refId,
                'description' => $description,
                'metadata_json' => array_merge($metadata ?? [], ['escrow_restore' => true]),
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Reverse a previous credit with a compensating ledger entry.
     * Idempotent: a second call returns the existing reversal.
     * Never drives the balance negative — any unrecovered shortfall is
     * recorded on the reversal entry for ops follow-up.
     */
    public function reverseCredit(Wallet $wallet, WalletTransaction $original, string $reason): WalletTransaction
    {
        $reversalType = $original->type . '_reversal';

        if (!in_array($reversalType, ['task_reward_reversal', 'referral_reward_reversal'], true)) {
            throw new Exception("Credits of type {$original->type} cannot be reversed.");
        }

        return DB::transaction(function () use ($wallet, $original, $reason, $reversalType) {
            $existing = WalletTransaction::where('reference_type', WalletTransaction::class)
                ->where('reference_id', $original->id)
                ->where('type', $reversalType)
                ->first();

            if ($existing) {
                return $existing;
            }

            $lockedWallet = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();

            if ((int) $lockedWallet->id !== (int) $original->wallet_id) {
                throw new Exception('Reversal wallet does not match the original credit wallet.');
            }

            $originalAmount = (int) $original->amount_cents;
            $reversible = max(0, min($lockedWallet->available_balance_cents, $originalAmount));

            $lockedWallet->decrement('available_balance_cents', $reversible);
            $lockedWallet->decrement(
                'lifetime_earnings_cents',
                min($lockedWallet->lifetime_earnings_cents, $reversible)
            );

            return WalletTransaction::create([
                'wallet_id' => $lockedWallet->id,
                'type' => $reversalType,
                'amount_cents' => -$reversible,
                'balance_after_cents' => $lockedWallet->available_balance_cents,
                'currency' => $lockedWallet->currency,
                'reference_type' => WalletTransaction::class,
                'reference_id' => $original->id,
                'description' => "Reversal of {$original->type} #{$original->id}: {$reason}",
                'metadata_json' => [
                    'original_amount_cents' => $originalAmount,
                    'reversed_amount_cents' => $reversible,
                    'unrecovered_shortfall_cents' => $originalAmount - $reversible,
                ],
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Request a withdrawal, debiting available balance and moving to pending.
     */
    public function requestWithdrawal(
        User $user,
        int $amountCents,
        string $payoutMethod,
        array $payoutDetails,
        ?string $idempotencyKey = null
    ): WithdrawalRequest {
        // Phase 2: single source of truth is the active DB withdrawal rule
        // (Super-Admin-selectable $10/$25/$50/$100); config is the fallback.
        $minWithdrawal = WithdrawalRule::currentMinCents();
        if ($amountCents < $minWithdrawal) {
            throw new Exception("Minimum withdrawal amount is " . number_format($minWithdrawal / 100, 2) . " USD.");
        }

        return $this->withIdempotency(
            $idempotencyKey,
            'wallet.withdraw',
            $user->id,
            [
                'user_id' => $user->id,
                'amount_cents' => $amountCents,
                'payout_method' => $payoutMethod,
                'payout_details_hash' => hash('sha256', json_encode($payoutDetails)),
            ],
            function () use ($user, $amountCents, $payoutMethod, $payoutDetails) {
                return DB::transaction(function () use ($user, $amountCents, $payoutMethod, $payoutDetails) {
                    $wallet = Wallet::firstOrCreate(
                        ['user_id' => $user->id],
                        ['currency' => 'USD', 'available_balance_cents' => 0]
                    );

                    $lockedWallet = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();

                    if ($lockedWallet->is_locked) {
                        throw new Exception('Wallet is currently locked for compliance review.');
                    }

                    if ($lockedWallet->available_balance_cents < $amountCents) {
                        throw new Exception('Insufficient available balance for withdrawal.');
                    }

                    // Move from available to pending
                    $lockedWallet->decrement('available_balance_cents', $amountCents);
                    $lockedWallet->increment('pending_balance_cents', $amountCents);

                    $withdrawal = WithdrawalRequest::create([
                        'wallet_id' => $lockedWallet->id,
                        'user_id' => $user->id,
                        'amount_cents' => $amountCents,
                        'fee_cents' => 0, // Zero fee model
                        'currency' => $lockedWallet->currency,
                        'payout_method' => $payoutMethod,
                        'payout_details_json' => $payoutDetails,
                        'status' => 'requested',
                    ]);

                    WalletTransaction::create([
                        'wallet_id' => $lockedWallet->id,
                        'type' => 'withdrawal',
                        'amount_cents' => -$amountCents,
                        'balance_after_cents' => $lockedWallet->available_balance_cents,
                        'currency' => $lockedWallet->currency,
                        'reference_type' => WithdrawalRequest::class,
                        'reference_id' => $withdrawal->id,
                        'description' => "Withdrawal request via {$payoutMethod}",
                        'metadata_json' => ['payout_method' => $payoutMethod],
                        'created_at' => now(),
                    ]);

                    return $withdrawal;
                });
            }
        );
    }

    /**
     * Approve a withdrawal: queue it for manual payout processing.
     *
     * IMPORTANT: PaymentService::payoutWithdrawal() is LOG-ONLY until a real
     * PSP is wired, so an approved withdrawal MUST get status `processing`
     * ("logged for manual processing — funds not yet sent"), NEVER `paid`.
     * Only a real payment-provider integration (a later phase) may set
     * status `paid`. The `paid` enum value exists for that future use.
     */
    public function approveWithdrawal(
        WithdrawalRequest $request,
        ?string $providerTxId = null,
        ?string $idempotencyKey = null
    ): WithdrawalRequest {
        return $this->withIdempotency(
            $idempotencyKey,
            'wallet.withdrawal.approve',
            $request->user_id,
            ['withdrawal_id' => $request->id, 'provider_tx_id' => $providerTxId],
            function () use ($request, $providerTxId) {
                // The gateway call lives inside the idempotent unit so a retried
                // request never triggers a second payout attempt.
                if (!$providerTxId) {
                    $payment = app(PaymentService::class)->payoutWithdrawal($request);
                    if (!($payment['ok'] ?? false)) {
                        throw new Exception($payment['message'] ?? 'Payment gateway payout failed.');
                    }
                    $providerTxId = $payment['provider_transaction_id'] ?? null;
                }

                return DB::transaction(function () use ($request, $providerTxId) {
                    $lockedRequest = WithdrawalRequest::where('id', $request->id)->lockForUpdate()->firstOrFail();
                    if (in_array($lockedRequest->status, ['paid', 'processing'], true)) {
                        return $lockedRequest;
                    }

                    $wallet = Wallet::where('id', $lockedRequest->wallet_id)->lockForUpdate()->firstOrFail();
                    $wallet->decrement('pending_balance_cents', $lockedRequest->amount_cents);
                    $wallet->increment('total_withdrawn_cents', $lockedRequest->amount_cents);

                    $lockedRequest->update([
                        // `processing` = queued/logged for manual processing.
                        // Real PSPs (later phase) are the only writers of `paid`.
                        'status' => 'processing',
                        'processed_at' => now(),
                        'provider_transaction_id' => $providerTxId ?? 'PAY_' . strtoupper(uniqid()),
                        'admin_notes' => 'Logged for manual processing'
                            . ($providerTxId ? " (ref {$providerTxId})" : '')
                            . ' — funds not yet sent.',
                    ]);

                    return $lockedRequest;
                });
            }
        );
    }

    /**
     * Reject withdrawal and reverse funds to available balance.
     */
    public function rejectWithdrawal(
        WithdrawalRequest $request,
        string $reason,
        ?string $idempotencyKey = null
    ): WithdrawalRequest {
        return $this->withIdempotency(
            $idempotencyKey,
            'wallet.withdrawal.reject',
            $request->user_id,
            ['withdrawal_id' => $request->id, 'reason' => $reason],
            function () use ($request, $reason) {
                return DB::transaction(function () use ($request, $reason) {
                    $lockedRequest = WithdrawalRequest::where('id', $request->id)->lockForUpdate()->firstOrFail();
                    if ($lockedRequest->status === 'rejected' || $lockedRequest->status === 'paid') {
                        return $lockedRequest;
                    }

                    $wallet = Wallet::where('id', $lockedRequest->wallet_id)->lockForUpdate()->firstOrFail();
                    $wallet->decrement('pending_balance_cents', $lockedRequest->amount_cents);
                    $wallet->increment('available_balance_cents', $lockedRequest->amount_cents);

                    $lockedRequest->update([
                        'status' => 'rejected',
                        'admin_notes' => $reason,
                        'processed_at' => now(),
                    ]);

                    WalletTransaction::create([
                        'wallet_id' => $wallet->id,
                        'type' => 'withdrawal_reversal',
                        'amount_cents' => $lockedRequest->amount_cents,
                        'balance_after_cents' => $wallet->available_balance_cents,
                        'currency' => $wallet->currency,
                        'reference_type' => WithdrawalRequest::class,
                        'reference_id' => $lockedRequest->id,
                        'description' => "Withdrawal reversal: {$reason}",
                        'created_at' => now(),
                    ]);

                    return $lockedRequest;
                });
            }
        );
    }

    /**
     * Run $work exactly once per idempotency key. A retry with the same key
     * and identical parameters returns the stored result instead of
     * re-applying; the same key with different parameters is rejected; a
     * concurrent in-flight request with the same key gets a 409-style error
     * instead of double-applying.
     *
     * @param callable(): mixed $work
     * @param callable(IdempotencyKey): mixed|null $resolveResult custom replay resolver
     */
    protected function withIdempotency(
        ?string $key,
        string $action,
        ?int $userId,
        array $fingerprintParts,
        callable $work,
        ?callable $resolveResult = null
    ): mixed {
        if (empty($key)) {
            return $work();
        }

        $fingerprint = hash('sha256', $action . '|' . json_encode($fingerprintParts));

        return DB::transaction(function () use ($key, $action, $userId, $fingerprint, $work, $resolveResult) {
            try {
                $record = IdempotencyKey::create([
                    'idempotency_key' => $key,
                    'user_id' => $userId,
                    'action' => $action,
                    'fingerprint' => $fingerprint,
                ]);
            } catch (QueryException $e) {
                // Key already seen: only a genuine duplicate-key collision has a
                // row to show for it; anything else is rethrown untouched.
                $record = IdempotencyKey::where('idempotency_key', $key)->first();

                if (!$record) {
                    throw $e;
                }

                $record = IdempotencyKey::where('idempotency_key', $key)->lockForUpdate()->firstOrFail();

                if (!hash_equals((string) $record->fingerprint, $fingerprint)) {
                    throw new Exception('Idempotency key was already used with different parameters.');
                }

                if ($record->result_type && $record->result_id) {
                    return $resolveResult ? $resolveResult($record) : $this->resolveStoredResult($record);
                }

                throw new Exception('A request with this idempotency key is already being processed.');
            }

            $result = $work();

            $record->update([
                'result_type' => get_class($result),
                'result_id' => $result->getKey(),
            ]);

            return $result;
        });
    }

    /**
     * Default idempotency replay resolver: re-fetch the record that the
     * original request created.
     */
    protected function resolveStoredResult(IdempotencyKey $record): mixed
    {
        $class = $record->result_type;

        return $class::findOrFail($record->result_id);
    }
}
