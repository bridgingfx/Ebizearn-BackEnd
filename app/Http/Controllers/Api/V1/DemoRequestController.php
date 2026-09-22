<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DemoRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Public "request a demo" submissions.
 *
 * store() is fully public (rate-limited by the demo-requests limiter) and
 * writes a real DemoRequest row. index() is admin/superadmin only and lists
 * submissions latest-first with pagination.
 */
class DemoRequestController extends Controller
{
    /**
     * Accept a demo request from the public site. No auth required.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:190',
            'company' => 'required|string|max:160',
            'message' => 'required|string|min:10|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        DemoRequest::create(array_merge(
            $validator->validated(),
            ['status' => 'new']
        ));

        return response()->json([
            'success' => true,
            'message' => 'Demo request received. Our team will be in touch shortly.',
        ], 201);
    }

    /**
     * Latest-first paginated list for admin/superadmin triage.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min(100, (int) $request->input('per_page', 25)));

        $requests = DemoRequest::query()
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $requests->items(),
            'meta' => [
                'current_page' => $requests->currentPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
                'last_page' => $requests->lastPage(),
            ],
        ]);
    }
}
