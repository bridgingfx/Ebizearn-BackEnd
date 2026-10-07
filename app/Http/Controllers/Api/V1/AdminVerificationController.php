<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Campaign;
use App\Models\FraudEvent;
use App\Models\Task;
use App\Models\TaskSubmission;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\Verification\VerificationService;
use App\Services\Idempotency\IdempotencyConflictException;
use App\Services\Wallet\WalletLedgerService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminVerificationController extends Controller
{
    public function __construct(
        protected VerificationService $verificationService = new VerificationService(),
        protected WalletLedgerService $walletService = new WalletLedgerService()
    ) {}

    /**
     * Admin Overview Dashboard Statistics.
     */
    public function dashboard(): JsonResponse
    {
        $totalContributors = User::where('role', 'contributor')->count();
        $totalBusinesses = Business::count();
        $activeCampaigns = Campaign::where('status', 'active')->count();
        $pendingVerification = TaskSubmission::where('status', 'under_review')->count();
        $pendingPayouts = WithdrawalRequest::whereIn('status', ['requested', 'compliance_check', 'processing'])->count();
        $fraudAlertsCount = FraudEvent::where('status', 'flagged')->count();

        // Revenue: platform fees collected (non-refundable, taken at launch)
        $totalRevenueCents = Campaign::whereIn('status', ['active', 'paused', 'completed'])
            ->sum('platform_fee_cents');
        $todayRevenueCents = Campaign::whereIn('status', ['active', 'paused', 'completed'])
            ->whereDate('created_at', today())
            ->sum('platform_fee_cents');

        // Pending deposits / withdrawals
        $pendingDeposits = \App\Models\DepositRequest::where('status', 'pending')->count() ?? 0;
        $pendingDepositsCents = \App\Models\DepositRequest::where('status', 'pending')->sum('amount_cents') ?? 0;

        // Recent signups (last 8 users)
        $recentUsers = User::with('profile')
            ->latest()
            ->take(8)
            ->get(['id', 'name', 'email', 'role', 'created_at']);

        // Recent verification submissions
        $queue = TaskSubmission::where('status', 'under_review')
            ->with(['task.category', 'task.campaign.business', 'user.profile', 'aiResult', 'files'])
            ->latest()
            ->take(8)
            ->get();

        // Recent fraud events — with human-readable descriptions for the dashboard.
        $recentFraud = FraudEvent::with(['user.profile', 'submission.task'])
            ->latest()
            ->take(5)
            ->get()
            ->map(function ($e) {
                $details = $e->details_json ?? [];
                return [
                    'id' => $e->id,
                    'event_type' => $e->event_type,
                    'severity' => $e->severity,
                    'status' => $e->status,
                    // Human-readable: what happened and why it was flagged.
                    'title' => $this->fraudTitle($e->event_type),
                    'description' => $details['message'] ?? $details['reason'] ?? $this->fraudDescription($e->event_type, $details),
                    'user_name' => $e->user?->name,
                    'user_id' => $e->user_id,
                    'ip_address' => $e->ip_address,
                    'created_at' => $e->created_at,
                ];
            });

        // 7-day revenue chart (platform fees per day)
        $revenueByDay = Campaign::whereIn('status', ['active', 'paused', 'completed'])
            ->where('created_at', '>=', now()->subDays(6)->startOfDay())
            ->selectRaw('DATE(created_at) as day, SUM(platform_fee_cents) as total')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $chart = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            $chart[] = [
                'day' => $day,
                'label' => now()->subDays($i)->format('D'),
                'revenue_cents' => (int) ($revenueByDay[$day]->total ?? 0),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'metrics' => [
                    'total_contributors' => $totalContributors,
                    'total_businesses' => $totalBusinesses,
                    'active_campaigns' => $activeCampaigns,
                    'pending_verification' => $pendingVerification,
                    'pending_payouts' => $pendingPayouts,
                    'fraud_alerts_count' => $fraudAlertsCount,
                    'total_revenue_cents' => (int) $totalRevenueCents,
                    'today_revenue_cents' => (int) $todayRevenueCents,
                    'pending_deposits' => $pendingDeposits,
                    'pending_deposits_cents' => (int) $pendingDepositsCents,
                ],
                'verification_queue' => $queue,
                'recent_fraud' => $recentFraud,
                'recent_users' => $recentUsers,
                'revenue_chart' => $chart,
            ],
        ]);
    }

    /**
     * Verification Queue with filters and pagination.
     */
    public function verificationQueue(Request $request): JsonResponse
    {
        $query = TaskSubmission::with(['task.category', 'task.campaign.business', 'user.profile', 'aiResult', 'files', 'businessReviewer:id,name', 'reviewer:id,name']);

        $status = $request->input('status', 'under_review');
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        // Two-step review: filter by the campaign business's recommendation.
        match ($request->input('business_decision')) {
            'approved', 'rejected' => $query->where('business_decision', $request->input('business_decision')),
            'none' => $query->whereNull('business_decision'),
            default => null,
        };

        if ($request->filled('search')) {
            $search = '%' . $request->input('search') . '%';
            $query->whereHas('user', fn($q) => $q->where('name', 'like', $search)->orWhere('email', 'like', $search));
        }

        $submissions = $query->latest()->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $submissions->items(),
            'meta' => [
                'current_page' => $submissions->currentPage(),
                'last_page' => $submissions->lastPage(),
                'total' => $submissions->total(),
            ],
        ]);
    }

    /**
     * Inspect single submission in full detail.
     */
    public function submissionDetail(string $id): JsonResponse
    {
        $submission = TaskSubmission::with([
            'task.category',
            'task.campaign.business',
            'user.profile',
            'user.wallet',
            'aiResult',
            'files',
            'reviewer',
            'businessReviewer:id,name',
        ])
        ->where('id', $id)
        ->orWhere('uuid', $id)
        ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $submission,
        ]);
    }

    /**
     * Admin executes Approve / Reject / Action Required decision with a
     * MANDATORY reason code (Phase 6) and reviewer notes.
     */
    public function recordDecision(Request $request, string $id): JsonResponse
    {
        $decision = $request->input('decision');

        $validator = Validator::make($request->all(), [
            'decision' => 'required|in:approved,rejected,action_required',
            'reason_code' => 'required|string',
            'notes' => 'required|string|min:3|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Reason code must come from the catalog for the chosen decision —
        // a 422 names the valid codes instead of failing silently.
        $validCodes = VerificationService::REASON_CODES[$decision] ?? [];
        if (!in_array($request->input('reason_code'), $validCodes, true)) {
            return response()->json([
                'success' => false,
                'message' => "Invalid reason code '{$request->input('reason_code')}' for decision '{$decision}'.",
                'valid_reason_codes' => $validCodes,
            ], 422);
        }

        $submission = TaskSubmission::where('id', $id)->orWhere('uuid', $id)->firstOrFail();
        $previousStatus = $submission->status;

        try {
            $updated = $this->verificationService->recordDecision(
                $submission,
                $request->user(),
                $decision,
                $request->input('reason_code'),
                $request->input('notes')
            );

            // Tell the contributor (once, only when the status actually changed).
            if ($previousStatus !== $updated->status && in_array($updated->status, ['approved', 'rejected'], true)) {
                $contributor = $updated->user;
                $task = $updated->task;
                app(\App\Services\Email\EmailService::class)->sendEvent(
                    $updated->status === 'approved' ? 'task_approved' : 'task_rejected',
                    $contributor->email,
                    [
                        'user_name' => $contributor->name,
                        'task_title' => (string) $task?->title,
                        'amount' => 'USD ' . number_format(((int) $task?->reward_cents) / 100, 2),
                        'reason' => (string) $request->input('notes'),
                    ],
                );
            }

            return response()->json([
                'success' => true,
                'message' => match ($decision) {
                    'approved' => "Proof approved. The reward has been released to the contributor's wallet.",
                    'rejected' => 'Proof rejected. The contributor has been notified.',
                    default => 'The contributor has been asked for more proof.',
                },
                'data' => $updated->load(['task', 'user.wallet', 'reviewer', 'aiResult']),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Fraud alerts list.
     */
    public function fraudAlerts(Request $request): JsonResponse
    {
        $alerts = FraudEvent::with(['user.profile', 'submission.task'])
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $alerts->items(),
            'meta' => [
                'current_page' => $alerts->currentPage(),
                'last_page' => $alerts->lastPage(),
                'total' => $alerts->total(),
            ],
        ]);
    }

    /**
     * Payouts management queue.
     */
    public function payouts(Request $request): JsonResponse
    {
        $query = WithdrawalRequest::with(['user.profile', 'wallet']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Lets admins queue one rail at a time (e.g. ?payout_method=usdt).
        if ($request->filled('payout_method')) {
            $query->where('payout_method', $request->input('payout_method'));
        }

        $payouts = $query->latest()->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $payouts->items(),
            'meta' => [
                'current_page' => $payouts->currentPage(),
                'last_page' => $payouts->lastPage(),
                'total' => $payouts->total(),
            ],
        ]);
    }

    /**
     * Process payout: approve (log for manual processing) or reject (reverse funds).
     */
    public function processPayout(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'action' => 'required|in:approve,reject',
            'reason' => 'required_if:action,reject|nullable|string',
            'provider_tx_id' => 'nullable|string',
            // On-chain tx hash when the admin already sent USDT manually.
            'tx_hash' => 'nullable|string|max:128',
            'idempotency_key' => 'nullable|string|max:128',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $withdrawal = WithdrawalRequest::where('id', $id)->orWhere('uuid', $id)->firstOrFail();
        $idempotencyKey = $request->header('Idempotency-Key') ?: $request->input('idempotency_key');

        try {
            if ($request->input('action') === 'approve') {
                $processed = $this->walletService->approveWithdrawal(
                    $withdrawal,
                    $request->input('provider_tx_id'),
                    $idempotencyKey,
                    $request->input('tx_hash')
                );
                $msg = 'Payout approved and logged for manual processing — funds not yet sent.';
            } else {
                $processed = $this->walletService->rejectWithdrawal($withdrawal, $request->input('reason', 'Compliance criteria not met'), $idempotencyKey);
                $msg = 'Payout rejected and balance returned to contributor wallet.';
            }

            return response()->json([
                'success' => true,
                'message' => $msg,
                'data' => $processed,
            ]);
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

    /**
     * Record the on-chain tx hash for a USDT payout after the admin sends
     * USDT manually from the company wallet. Only valid while the payout
     * is `processing` (approved, awaiting/just-sent manual payout).
     */
    public function recordTxHash(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tx_hash' => 'required|string|max:128',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $withdrawal = WithdrawalRequest::where('id', $id)->orWhere('uuid', $id)->firstOrFail();

        if ($withdrawal->payout_method !== 'usdt') {
            return response()->json([
                'success' => false,
                'message' => 'A tx hash can only be recorded for USDT payouts.',
            ], 422);
        }

        if ($withdrawal->status !== 'processing') {
            return response()->json([
                'success' => false,
                'message' => 'A tx hash can only be recorded on an approved (processing) payout.',
            ], 422);
        }

        $withdrawal->update(['tx_hash' => $request->input('tx_hash')]);

        return response()->json([
            'success' => true,
            'message' => 'Tx hash recorded.',
            'data' => $withdrawal->fresh(),
        ]);
    }

    /**
     * Human-readable title for a fraud event type (dashboard display).
     */
    private function fraudTitle(?string $eventType): string
    {
        return match ($eventType) {
            'duplicate_ip' => 'Multiple accounts from same IP',
            'fake_screenshot' => 'Suspicious screenshot detected',
            'deleted_post' => 'Promoted post deleted',
            'modified_post' => 'Promoted post modified after approval',
            'rapid_submissions' => 'Unusually fast submissions',
            'vpn_detected' => 'VPN or proxy detected',
            'account_takeover' => 'Possible account takeover',
            default => $eventType ? ucwords(str_replace('_', ' ', $eventType)) : 'Fraud alert',
        };
    }

    /**
     * Human-readable explanation of why a fraud event was flagged.
     */
    private function fraudDescription(?string $eventType, array $details): string
    {
        $base = match ($eventType) {
            'duplicate_ip' => 'Two or more accounts submitted from the same IP address.',
            'fake_screenshot' => 'The submitted screenshot failed authenticity checks.',
            'deleted_post' => 'The contributor deleted the promoted post during the retention period.',
            'modified_post' => 'The promoted post was edited after the submission was approved.',
            'rapid_submissions' => 'Submissions were made far faster than a human could complete them.',
            'vpn_detected' => 'The contributor appears to be hiding their real location.',
            'account_takeover' => 'Login behavior suggests someone else may be using this account.',
            default => 'This activity was flagged by the automated fraud checks.',
        };
        if (!empty($details['ip_address'])) {
            $base .= ' IP: ' . $details['ip_address'] . '.';
        }
        return $base;
    }
}
