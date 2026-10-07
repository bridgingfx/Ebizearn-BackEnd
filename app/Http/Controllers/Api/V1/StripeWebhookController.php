<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Payment\StripeDepositService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public Stripe webhook (additive 2026-10-07).
 *
 * No auth — Stripe signs every call and the signature is verified before
 * anything happens. On a completed checkout the customer's wallet is
 * credited automatically.
 */
class StripeWebhookController extends Controller
{
    public function handle(Request $request, StripeDepositService $stripe): JsonResponse
    {
        $result = $stripe->handleWebhook(
            $request->getContent(),
            $request->header('Stripe-Signature')
        );

        return response()->json(
            ['success' => $result['ok'], 'message' => $result['message'] ?? null],
            $result['ok'] ? 200 : 400
        );
    }
}
