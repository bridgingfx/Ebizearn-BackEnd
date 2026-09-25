<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DepositMethod;
use App\Models\DepositRequest;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Audit\AuditLogger;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Business wallet deposits: methods configured by Super Admin, requests made
 * by businesses, credited to the wallet only after staff approval.
 */
class DepositController extends Controller
{
    private const MAX_OPEN_REQUESTS = 5;

    // ------------------------------------------------------------------
    // Business
    // ------------------------------------------------------------------

    /** GET /business/deposit-methods — active methods with their payment details. */
    public function methods(): JsonResponse
    {
        return $this->ok(
            DepositMethod::where('is_active', true)->orderBy('sort_order')
                ->get(['key', 'title', 'instructions', 'details', 'min_amount_cents', 'max_amount_cents'])
        );
    }

    /** GET /business/deposits — own deposit requests + recent wallet transactions. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $wallet = $this->walletFor($user->id);

        return $this->ok([
            'wallet' => $wallet->only(['id', 'currency', 'available_balance_cents', 'pending_balance_cents']),
            'deposits' => DepositRequest::where('user_id', $user->id)->latest('id')->limit(50)->get(),
            'transactions' => WalletTransaction::where('wallet_id', $wallet->id)->latest('id')->limit(50)
                ->get(['id', 'type', 'amount_cents', 'balance_after_cents', 'currency', 'description', 'reference_type', 'reference_id', 'created_at']),
        ]);
    }

    /** POST /business/deposits (multipart) { method, amount, reference?, note?, proof? } */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', Rule::in(DepositMethod::KEYS)],
            'amount' => 'required|numeric|min:1|max:1000000',
            'reference' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:1000',
            'proof' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
        ], [
            'proof.max' => 'The payment proof must be 5 MB or smaller.',
            'proof.mimes' => 'Upload the payment proof as an image or PDF.',
        ]);

        $method = DepositMethod::where('key', $data['method'])->where('is_active', true)->first();
        abort_unless($method, 422, 'This deposit method is not available right now.');

        $amountCents = (int) round(((float) $data['amount']) * 100);
        if ($amountCents < $method->min_amount_cents) {
            return $this->fail('amount', 'The minimum deposit with ' . $method->title . ' is ' . $this->money($method->min_amount_cents) . '.');
        }
        if ($method->max_amount_cents && $amountCents > $method->max_amount_cents) {
            return $this->fail('amount', 'The maximum deposit with ' . $method->title . ' is ' . $this->money($method->max_amount_cents) . '.');
        }
        if (in_array($method->key, ['crypto', 'card'], true) && empty($data['reference'])) {
            return $this->fail('reference', $method->key === 'crypto'
                ? 'Paste the transaction hash of your crypto transfer.'
                : 'Enter the payment reference from your card payment receipt.');
        }

        $user = $request->user();
        $open = DepositRequest::where('user_id', $user->id)->where('status', 'pending')->count();
        abort_if($open >= self::MAX_OPEN_REQUESTS, 422, 'You already have ' . self::MAX_OPEN_REQUESTS . ' deposits waiting for review. Please wait until they are processed.');

        if (!empty($data['reference'])) {
            $duplicate = DepositRequest::where('method', $method->key)->where('reference', trim($data['reference']))
                ->whereIn('status', ['pending', 'approved'])->exists();
            if ($duplicate) {
                return $this->fail('reference', 'This payment reference has already been submitted.');
            }
        }

        $wallet = $this->walletFor($user->id);
        $proofPath = $request->hasFile('proof') ? $request->file('proof')->store('deposits/' . $user->id, 'local') : null;

        $deposit = DepositRequest::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'method' => $method->key,
            'amount_cents' => $amountCents,
            'currency' => $wallet->currency ?: 'USD',
            'reference' => isset($data['reference']) ? trim($data['reference']) : null,
            'note' => $data['note'] ?? null,
            'proof_path' => $proofPath,
            'status' => 'pending',
        ]);

        AuditLogger::log($user, 'deposit.requested', DepositRequest::class, $deposit->id, [
            'method' => $method->key, 'amount_cents' => $amountCents,
        ]);

        return $this->ok($deposit, $method->key === 'email'
            ? 'Request sent. Our finance team will email you the payment details.'
            : 'Deposit submitted. Your wallet is credited as soon as we confirm the payment.', 201);
    }

    // ------------------------------------------------------------------
    // Staff (process_payouts)
    // ------------------------------------------------------------------

    /** GET /admin/deposits?status=pending|approved|rejected|all */
    public function staffIndex(Request $request): JsonResponse
    {
        $status = $request->input('status', 'pending');
        $query = DepositRequest::with(['user:id,name,email,role', 'user.business:id,owner_id,company_name', 'reviewer:id,name']);
        if ($status !== 'all') {
            $query->where('status', $status);
        }
        if ($request->filled('search')) {
            $s = '%' . $request->input('search') . '%';
            $query->where(fn ($q) => $q->where('reference', 'like', $s)
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $s)->orWhere('email', 'like', $s)));
        }

        $page = $query->latest('id')->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'pending' => DepositRequest::where('status', 'pending')->count(),
                'pending_amount_cents' => (int) DepositRequest::where('status', 'pending')->sum('amount_cents'),
            ],
        ]);
    }

    /** GET /admin/deposits/{id}/proof — streams the uploaded payment proof. */
    public function proof(int $id): Response
    {
        $deposit = DepositRequest::findOrFail($id);
        $path = $deposit->getAttributes()['proof_path'] ?? null;
        abort_unless($path && Storage::disk('local')->exists($path), 404, 'No proof was uploaded.');

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, no-store']);
    }

    /**
     * POST /admin/deposits/{id}/decision { decision: approve|reject, note?, amount? }
     * Approve credits the business wallet (the confirmed amount, which may
     * differ from the requested one, e.g. after fees).
     */
    public function decision(Request $request, int $id, WalletLedgerService $ledger): JsonResponse
    {
        $data = $request->validate([
            'decision' => 'required|in:approve,reject',
            'note' => 'required_if:decision,reject|nullable|string|max:500',
            'amount' => 'nullable|numeric|min:0.01|max:1000000',
        ], [
            'note.required_if' => 'Tell the business why the deposit was rejected.',
        ]);

        $deposit = DB::transaction(function () use ($data, $id, $request, $ledger) {
            $deposit = DepositRequest::where('id', $id)->lockForUpdate()->firstOrFail();
            abort_unless($deposit->status === 'pending', 422, 'This deposit has already been ' . $deposit->status . '.');

            if ($data['decision'] === 'reject') {
                $deposit->update([
                    'status' => 'rejected',
                    'reviewed_by' => $request->user()->id,
                    'reviewed_at' => now(),
                    'review_note' => $data['note'],
                ]);

                return $deposit;
            }

            $amountCents = isset($data['amount']) ? (int) round(((float) $data['amount']) * 100) : $deposit->amount_cents;
            $method = DepositMethod::where('key', $deposit->method)->value('title') ?? ucfirst($deposit->method);

            $tx = $ledger->credit(
                Wallet::findOrFail($deposit->wallet_id),
                $amountCents,
                'deposit',
                'Deposit via ' . $method . ($deposit->reference ? ' (' . $deposit->reference . ')' : ''),
                'deposit_request',
                $deposit->id,
                ['method' => $deposit->method, 'requested_cents' => $deposit->amount_cents, 'approved_by' => $request->user()->id],
                'deposit:' . $deposit->uuid,
            );

            $deposit->update([
                'status' => 'approved',
                'amount_cents' => $amountCents,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_note' => $data['note'] ?? null,
                'wallet_transaction_id' => $tx->id,
            ]);

            return $deposit;
        });

        AuditLogger::log($request->user(), 'deposit.' . ($deposit->status === 'approved' ? 'approved' : 'rejected'), DepositRequest::class, $deposit->id, [
            'amount_cents' => $deposit->amount_cents, 'method' => $deposit->method, 'business_user_id' => $deposit->user_id,
        ]);

        return $this->ok(
            $deposit->fresh()->load(['user:id,name,email,role', 'user.business:id,owner_id,company_name', 'reviewer:id,name']),
            $deposit->status === 'approved'
                ? $this->money($deposit->amount_cents) . ' was added to the business wallet.'
                : 'Deposit rejected. The business can see your reason.',
        );
    }

    // ------------------------------------------------------------------
    // Super Admin: methods
    // ------------------------------------------------------------------

    /** GET /admin/deposit-methods */
    public function adminMethods(): JsonResponse
    {
        return $this->ok(DepositMethod::orderBy('sort_order')->get());
    }

    /** PUT /admin/deposit-methods/{key} { is_active, title, instructions, details, min_amount, max_amount } */
    public function updateMethod(Request $request, string $key): JsonResponse
    {
        $method = DepositMethod::where('key', $key)->firstOrFail();

        $data = $request->validate([
            'is_active' => 'required|boolean',
            'title' => 'required|string|max:100',
            'instructions' => 'nullable|string|max:2000',
            'details' => 'nullable|array',
            'details.*' => 'nullable|string|max:500',
            'min_amount' => 'required|numeric|min:1|max:1000000',
            'max_amount' => 'nullable|numeric|min:1|max:10000000|gte:min_amount',
        ], [
            'max_amount.gte' => 'The maximum must be at least the minimum amount.',
        ]);

        // Keep only the fields this method uses.
        $details = collect($data['details'] ?? [])
            ->only(DepositMethod::DETAIL_FIELDS[$method->key])
            ->map(fn ($v) => is_string($v) ? trim($v) : $v)
            ->all();

        if ($data['is_active']) {
            $missing = $this->missingDetails($method->key, $details);
            if ($missing) {
                return response()->json([
                    'success' => false,
                    'message' => 'Fill in ' . $missing . ' before turning ' . $data['title'] . ' on.',
                ], 422);
            }
        }

        $before = $method->only(['is_active', 'title', 'details', 'min_amount_cents', 'max_amount_cents']);

        $method->update([
            'is_active' => $data['is_active'],
            'title' => $data['title'],
            'instructions' => $data['instructions'] ?? null,
            'details' => $details,
            'min_amount_cents' => (int) round(((float) $data['min_amount']) * 100),
            'max_amount_cents' => isset($data['max_amount']) ? (int) round(((float) $data['max_amount']) * 100) : null,
        ]);

        AuditLogger::log($request->user(), 'deposit_method.updated', DepositMethod::class, $method->id, ['key' => $method->key], $before,
            $method->only(['is_active', 'title', 'details', 'min_amount_cents', 'max_amount_cents']));

        return $this->ok($method->fresh(), $method->is_active
            ? $method->title . ' is now available on the business Billing page.'
            : $method->title . ' is turned off.');
    }

    // ------------------------------------------------------------------

    private function missingDetails(string $key, array $d): ?string
    {
        $has = fn (string $f) => !empty($d[$f]);

        return match ($key) {
            'card' => filter_var($d['payment_link'] ?? '', FILTER_VALIDATE_URL) ? null : 'a valid payment link (https://…)',
            'crypto' => $has('wallet_address') && $has('network') ? null : 'the wallet address and network',
            'bank' => $has('account_name') && ($has('iban') || $has('account_number')) ? null : 'the account name and IBAN or account number',
            'email' => filter_var($d['contact_email'] ?? '', FILTER_VALIDATE_EMAIL) ? null : 'a valid contact email',
            default => null,
        };
    }

    private function walletFor(int $userId): Wallet
    {
        return Wallet::firstOrCreate(['user_id' => $userId], [
            'currency' => 'USD',
            'available_balance_cents' => 0,
            'pending_balance_cents' => 0,
            'lifetime_earnings_cents' => 0,
            'total_withdrawn_cents' => 0,
        ]);
    }

    private function money(int $cents): string
    {
        return 'USD ' . number_format($cents / 100, 2);
    }

    private function fail(string $field, string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'errors' => [$field => [$message]]], 422);
    }

    private function ok($data, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }
}
