<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WithdrawalRule;
use App\Rules\UsdtAddress;
use App\Services\Idempotency\IdempotencyConflictException;
use App\Services\Wallet\WalletBreakdownService;
use App\Services\Wallet\WalletLedgerService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WalletController extends Controller
{
    public function __construct(
        protected WalletLedgerService $ledgerService = new WalletLedgerService(),
        protected WalletBreakdownService $breakdownService = new WalletBreakdownService()
    ) {}

    /**
     * Get wallet details and balance breakdown.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $wallet = Wallet::firstOrCreate(
            ['user_id' => $user->id],
            ['currency' => 'USD', 'available_balance_cents' => 0]
        );

        return response()->json([
            'success' => true,
            'data' => [
                'wallet' => $wallet,
                // Phase 7: every figure computed from the real ledger +
                // submission states — no stored denormalized balances.
                'breakdown' => $this->breakdownService->breakdown($user),
                // Phase 2: DB-backed, Super-Admin-selectable ($10/$25/$50/$100).
                'min_withdrawal_cents' => WithdrawalRule::currentMinCents(),
            ],
        ]);
    }

    /**
     * Phase 7: wallet breakdown as its own endpoint.
     */
    public function breakdown(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->breakdownService->breakdown($request->user()),
        ]);
    }

    /**
     * Get immutable ledger transactions with pagination.
     */
    public function transactions(Request $request): JsonResponse
    {
        $user = $request->user();
        $wallet = $user->wallet;

        if (!$wallet) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $query = WalletTransaction::where('wallet_id', $wallet->id);

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        // Ledger order is the append-only id sequence: created_at is not
        // unique (several rows can share a timestamp), so ordering by id
        // keeps pagination stable and matches the ledger's own ordering.
        $transactions = $query->latest('id')->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $transactions->items(),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'total' => $transactions->total(),
            ],
        ]);
    }

    /**
     * Submit a withdrawal request.
     */
    public function withdraw(Request $request): JsonResponse
    {
        // Phase 2: the minimum is the active DB withdrawal rule
        // (Super-Admin-selectable $10/$25/$50/$100), config as fallback.
        $minWithdrawalCents = WithdrawalRule::currentMinCents();

        $rules = [
            'amount_cents' => 'required|integer|min:' . $minWithdrawalCents,
            // USDT payouts are manual-approved (admin sends from the company
            // wallet and records the tx hash). Ledger stays USD (1 USDT = $1).
            'payout_method' => 'required|in:bank_transfer,paypal,wise,usdt',
            'payout_details' => 'required|array',
            'idempotency_key' => 'nullable|string|max:128',
        ];

        // USDT needs a validated on-chain address; other rails keep their
        // free-form payout_details untouched.
        if ($request->input('payout_method') === 'usdt') {
            $rules['payout_details.network'] = 'required|in:TRC-20,ERC-20';
            $rules['payout_details.wallet_address'] = [
                'required',
                'string',
                'max:128',
                new UsdtAddress($request->input('payout_details.network', 'TRC-20')),
            ];
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $withdrawal = $this->ledgerService->requestWithdrawal(
                $request->user(),
                (int) $request->input('amount_cents'),
                $request->input('payout_method'),
                $request->input('payout_details'),
                $request->header('Idempotency-Key') ?: $request->input('idempotency_key')
            );

            return response()->json([
                'success' => true,
                'message' => 'Withdrawal request submitted successfully.',
                'data' => $withdrawal,
            ], 201);
        } catch (IdempotencyConflictException $e) {
            // Same key replayed concurrently or with different parameters:
            // a conflict, not a bad request.
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 409);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
