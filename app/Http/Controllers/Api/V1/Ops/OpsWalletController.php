<?php

namespace App\Http\Controllers\Api\V1\Ops;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Staff\StaffScope;
use App\Services\Wallet\WalletLedgerService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Super-Admin wallet APIs (additive 2026-10-07).
 *
 * Dedicated, RESTful wallet endpoints: list/search wallets, inspect one
 * with its ledger, and grant "virtual tokens" — manual credits (or
 * corrective debits) applied straight to a business wallet, fully
 * ledger-backed and audited. Deposit approvals remain in DepositController.
 */
class OpsWalletController extends Controller
{
    public function __construct(
        protected WalletLedgerService $ledger = new WalletLedgerService()
    ) {}

    /** GET /admin/ops/wallets?search=&page= — paginated wallet directory. */
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('search', ''));

        $query = Wallet::with(['user:id,name,email,role', 'user.business:id,owner_id,company_name'])
            ->orderByDesc('available_balance_cents');
        StaffScope::apply($query, $request->user());

        if ($search !== '') {
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhereHas('business', fn ($b) => $b->where('company_name', 'like', "%{$search}%"));
            });
        }

        $wallets = $query->paginate(25);

        $wallets->getCollection()->transform(fn (Wallet $w) => $this->serialize($w));

        return response()->json(['success' => true, 'data' => $wallets]);
    }

    /** GET /admin/ops/wallets/{id} — wallet + recent ledger entries. */
    public function show(Request $request, int $id): JsonResponse
    {
        $wallet = Wallet::with(['user:id,name,email,role', 'user.business:id,owner_id,company_name'])->findOrFail($id);
        if (!StaffScope::allowsUser($request->user(), $wallet->user_id)) {
            return StaffScope::notFound();
        }

        $transactions = WalletTransaction::where('wallet_id', $wallet->id)
            ->latest('id')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'wallet' => $this->serialize($wallet),
                'transactions' => $transactions,
            ],
        ]);
    }

    /**
     * POST /admin/ops/wallets/{id}/credit { amount, description? }
     * Grant virtual tokens — a manual, ledger-backed credit to the wallet.
     */
    public function credit(Request $request, int $id, WalletLedgerService $ledger): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:1|max:1000000',
            'description' => 'required|string|min:3|max:500',
        ]);

        $wallet = Wallet::findOrFail($id);
        if (!StaffScope::allowsUser($request->user(), $wallet->user_id)) {
            return StaffScope::notFound();
        }
        $cents = (int) round(((float) $data['amount']) * 100);

        try {
            $tx = $ledger->credit(
                $wallet,
                $cents,
                'admin_adjustment', // only ledger type for manual changes (enum)
                $data['description'],
                'user',
                $request->user()->id,
                ['direction' => 'credit', 'granted_by' => $request->user()->id, 'granted_by_email' => $request->user()->email],
                'manual-credit:' . $wallet->id . ':' . time() . ':' . $request->user()->id,
            );
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $this->audit($request, 'wallet.manual_credit', $wallet, [
            'amount_cents' => $cents,
            'transaction_id' => $tx->id,
            'description' => $data['description'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Credited ' . $this->money($cents) . ' to the wallet.',
            'data' => ['wallet' => $this->serialize($wallet->fresh()), 'transaction' => $tx],
        ]);
    }

    /**
     * POST /admin/ops/wallets/{id}/debit { amount, description? }
     * Corrective debit — removes funds, never below zero.
     */
    public function debit(Request $request, int $id, WalletLedgerService $ledger): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:1|max:1000000',
            'description' => 'required|string|min:3|max:500',
        ]);

        $wallet = Wallet::findOrFail($id);
        if (!StaffScope::allowsUser($request->user(), $wallet->user_id)) {
            return StaffScope::notFound();
        }
        $cents = (int) round(((float) $data['amount']) * 100);

        try {
            $tx = $ledger->debit(
                $wallet,
                $cents,
                'admin_adjustment',
                $data['description'],
                'user',
                $request->user()->id,
                ['direction' => 'debit', 'debited_by' => $request->user()->id, 'debited_by_email' => $request->user()->email],
            );
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $this->audit($request, 'wallet.manual_debit', $wallet, [
            'amount_cents' => $cents,
            'transaction_id' => $tx->id,
            'description' => $data['description'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Debited ' . $this->money($cents) . ' from the wallet.',
            'data' => ['wallet' => $this->serialize($wallet->fresh()), 'transaction' => $tx],
        ]);
    }

    // ------------------------------------------------------------------

    protected function serialize(Wallet $w): array
    {
        return [
            'id' => $w->id,
            'currency' => $w->currency,
            'available_balance_cents' => (int) $w->available_balance_cents,
            'pending_balance_cents' => (int) $w->pending_balance_cents,
            'lifetime_earnings_cents' => (int) $w->lifetime_earnings_cents,
            'user' => $w->user ? [
                'id' => $w->user->id,
                'name' => $w->user->name,
                'email' => $w->user->email,
                'role' => $w->user->role,
                'company_name' => $w->user->business->company_name ?? null,
            ] : null,
        ];
    }

    protected function money(int $cents): string
    {
        return '$' . number_format($cents / 100, 2);
    }

    protected function audit(Request $request, string $action, Wallet $wallet, array $extra): void
    {
        AuditLog::create([
            'actor_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => Wallet::class,
            'entity_id' => $wallet->id,
            'before_state_json' => ['available_balance_cents' => (int) $wallet->available_balance_cents],
            'after_state_json' => $extra,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);
    }
}
