<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CountryChangeRequest;
use App\Models\Profile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Staff review of residence-country change requests (review_kyc).
 *
 * Approving switches the profile country and resets KYC for the new
 * country: kyc_country_code is stamped with the new country and the status
 * goes back to unverified, so TaskController::kycLockResponse keeps tasks
 * locked until KYC with documents from the new country is approved.
 */
class StaffCountryChangeController extends Controller
{
    /** GET /staff/country-changes?status=pending|approved|rejected|cancelled|all */
    public function index(Request $request): JsonResponse
    {
        $status = $request->input('status', 'pending');

        $query = CountryChangeRequest::with(['user:id,name,email,role', 'user.profile:id,user_id,kyc_status,country_code', 'reviewer:id,name'])
            ->latest('id');
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $page = $query->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'pending_count' => CountryChangeRequest::where('status', 'pending')->count(),
            ],
        ]);
    }

    /** POST /staff/country-changes/{id}/decision  { decision: approve|reject, note? } */
    public function decision(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'decision' => 'required|in:approve,reject',
            'note' => 'required_if:decision,reject|nullable|string|max:500',
        ], [
            'note.required_if' => 'Give the user a reason for the rejection.',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $approve = $request->input('decision') === 'approve';
        $actor = $request->user();

        $result = DB::transaction(function () use ($id, $approve, $actor, $request) {
            $change = CountryChangeRequest::lockForUpdate()->findOrFail($id);
            if ($change->status !== 'pending') {
                return null;
            }

            $change->forceFill([
                'status' => $approve ? 'approved' : 'rejected',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_note' => $request->input('note'),
            ])->save();

            if ($approve) {
                $profile = Profile::firstOrCreate(['user_id' => $change->user_id], ['country_code' => $change->to_country, 'language' => 'en']);
                $profile->country_code = $change->to_country;
                // KYC must be redone for the new residence country; the
                // old documents stay on file for audit.
                $profile->kyc_status = 'unverified';
                $profile->kyc_verified_at = null;
                $profile->kyc_reviewed_by = null;
                $profile->kyc_country_code = $change->to_country;
                $profile->save();
            }

            return $change;
        });

        if (!$result) {
            return response()->json(['success' => false, 'message' => 'This request is no longer awaiting review.'], 422);
        }

        AuditLogger::log(
            $actor,
            $approve ? 'profile.country_change_approved' : 'profile.country_change_rejected',
            User::class,
            $result->user_id,
            [],
            ['country_code' => $result->from_country],
            ['country_code' => $approve ? $result->to_country : $result->from_country, 'note' => $result->review_note]
        );

        return response()->json([
            'success' => true,
            'message' => $approve
                ? "Approved — country is now {$result->to_country}. The user must complete KYC for the new country before doing tasks."
                : 'Country change rejected.',
            'data' => $result->load(['user:id,name,email,role', 'reviewer:id,name']),
        ]);
    }
}
