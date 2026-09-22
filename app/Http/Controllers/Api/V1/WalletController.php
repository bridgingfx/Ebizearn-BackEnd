<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WithdrawalRule;
use App\Services\Wallet\WalletLedgerService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WalletController extends Controller
{
    public function __construct(
        protected WalletLedgerService $ledgerService = new WalletLedgerService()
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
                // Phase 2: DB-backed, Super-Admin-selectable ($10/$25/$50/$100).
                'min_withdrawal_cents' => WithdrawalRule::currentMinCents(),
            ],
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

        $transactions = $query->latest('created_at')->paginate(20);

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

        $validator = Validator::make($request->all(), [
            'amount_cents' => 'required|integer|min:' . $minWithdrawalCents,
            // No crypto in MVP (owner-adjudicated rule): only fiat rails.
            'payout_method' => 'required|in:bank_transfer,paypal,wise',
            'payout_details' => 'required|array',
            'idempotency_key' => 'nullable|string|max:128',
        ]);

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
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
