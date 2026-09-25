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

        // Recent verification submissions
        $queue = TaskSubmission::where('status', 'under_review')
            ->with(['task.category', 'task.campaign.business', 'user.profile', 'aiResult', 'files'])
            ->latest()
            ->take(8)
            ->get();

        // Recent fraud events
        $recentFraud = FraudEvent::with(['user.profile', 'submission.task'])
            ->latest()
            ->take(5)
            ->get();

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
                ],
                'verification_queue' => $queue,
                'recent_fraud' => $recentFraud,
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
                $processed = $this->walletService->approveWithdrawal($withdrawal, $request->input('provider_tx_id'), $idempotencyKey);
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
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
