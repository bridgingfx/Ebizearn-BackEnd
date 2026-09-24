<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Email\EmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

/**
 * KYC review queue for moderators / admins / super admins. Documents are on
 * the private `local` disk and are only ever streamed through this API.
 */
class StaffKycController extends Controller
{
    private const SIDES = [
        'front' => 'kyc_front_path',
        'back' => 'kyc_back_path',
        'selfie' => 'kyc_selfie_path',
    ];

    /**
     * GET /staff/kyc?status=pending|verified|rejected|all
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->input('status', 'pending');

        $query = Profile::query()
            ->with(['user:id,uuid,name,email,role,status,created_at'])
            ->whereNotNull('kyc_submitted_at');

        if ($status !== 'all') {
            $query->where('kyc_status', $status);
        }

        if ($request->filled('search')) {
            $search = '%' . $request->input('search') . '%';
            $query->whereHas('user', fn ($q) => $q->where('name', 'like', $search)->orWhere('email', 'like', $search));
        }

        $page = $query->orderByDesc('kyc_submitted_at')->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'pending' => Profile::where('kyc_status', 'pending')->count(),
            ],
        ]);
    }

    /**
     * GET /staff/kyc/{userId}/documents/{side} — streams one document file.
     */
    public function document(string $userId, string $side): Response
    {
        $column = self::SIDES[$side] ?? null;
        $profile = Profile::where('user_id', $userId)->first();
        $path = $column && $profile ? ($profile->getAttributes()[$column] ?? null) : null;

        if (!$path || !Storage::disk('local')->exists($path)) {
            return response()->json(['success' => false, 'message' => 'Document not found.'], 404);
        }

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * POST /staff/kyc/{userId}/decision  { decision: approve|reject, reason? }
     */
    public function decision(Request $request, string $userId, EmailService $emails): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'decision' => 'required|in:approve,reject',
            'reason' => 'required_if:decision,reject|nullable|string|max:500',
        ], [
            'reason.required_if' => 'Give the user a reason for the rejection.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::with('profile')->findOrFail($userId);
        $profile = $user->profile;

        if (!$profile || $profile->kyc_status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'This KYC submission is not awaiting review.',
            ], 422);
        }

        $approve = $request->input('decision') === 'approve';
        $before = ['kyc_status' => $profile->kyc_status];

        $profile->update([
            'kyc_status' => $approve ? 'verified' : 'rejected',
            'kyc_verified_at' => $approve ? now() : null,
            'kyc_reviewed_by' => $request->user()->id,
            'kyc_rejection_reason' => $approve ? null : $request->input('reason'),
        ]);

        AuditLogger::log(
            $request->user(),
            $approve ? 'kyc.approved' : 'kyc.rejected',
            User::class,
            $user->id,
            [],
            $before,
            ['kyc_status' => $profile->kyc_status, 'reason' => $profile->kyc_rejection_reason]
        );

        $emails->sendEvent(
            $approve ? 'kyc_approved' : 'kyc_rejected',
            $user->email,
            ['user_name' => $user->name, 'reason' => (string) $request->input('reason', '')]
        );

        return response()->json([
            'success' => true,
            'message' => $approve ? 'KYC approved.' : 'KYC rejected.',
            'data' => $profile->fresh()->load('user:id,uuid,name,email,role,status,created_at'),
        ]);
    }
}
