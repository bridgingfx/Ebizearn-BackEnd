<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PaymentGateway;
use App\Models\PaymentLog;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminPaymentController extends Controller
{
    public function gateways(): JsonResponse
    {
        return $this->ok(PaymentGateway::orderByDesc('is_active')->orderBy('name')->get());
    }

    public function storeGateway(Request $request): JsonResponse
    {
        $gateway = PaymentGateway::create($this->validateGateway($request));
        $this->audit($request, 'payment_gateway.created', $gateway);

        return $this->ok($gateway, 'Payment gateway created.', 201);
    }

    public function updateGateway(Request $request, int $id): JsonResponse
    {
        $gateway = PaymentGateway::findOrFail($id);
        $data = $this->validateGateway($request, $gateway);

        if (empty($data['credentials'])) {
            unset($data['credentials']);
        }

        $gateway->update($data);
        $this->audit($request, 'payment_gateway.updated', $gateway);

        return $this->ok($gateway->fresh(), 'Payment gateway updated.');
    }

    public function destroyGateway(Request $request, int $id): JsonResponse
    {
        $gateway = PaymentGateway::findOrFail($id);
        $this->audit($request, 'payment_gateway.deleted', $gateway);
        $gateway->delete();

        return $this->ok(null, 'Payment gateway deleted.');
    }

    public function setActive(Request $request, int $id): JsonResponse
    {
        $gateway = PaymentGateway::findOrFail($id);
        $active = $request->boolean('is_active', true);

        DB::transaction(function () use ($gateway, $active) {
            if ($active) {
                PaymentGateway::where('id', '!=', $gateway->id)
                    ->where('driver', $gateway->driver)
                    ->update(['is_active' => false]);
            }
            $gateway->update(['is_active' => $active]);
        });

        $this->audit($request, $active ? 'payment_gateway.activated' : 'payment_gateway.disabled', $gateway);

        return $this->ok($gateway->fresh(), $active ? 'Gateway is now active.' : 'Gateway disabled.');
    }

    public function testGateway(int $id, PaymentService $payments): JsonResponse
    {
        $gateway = PaymentGateway::findOrFail($id);
        $result = $payments->testGateway($gateway);

        return response()->json([
            'success' => $result['ok'],
            'message' => $result['message'],
            'data' => $gateway->fresh(),
        ], $result['ok'] ? 200 : 422);
    }

    public function logs(): JsonResponse
    {
        return $this->ok(PaymentLog::orderByDesc('id')->limit(100)->get());
    }

    private function validateGateway(Request $request, ?PaymentGateway $existing = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'driver' => ['required', Rule::in(PaymentGateway::DRIVERS)],
            'display_name' => 'nullable|string|max:255',
            'credentials' => 'nullable|array',
        ]);

        $credentialsStored = $existing?->has_credentials && ($existing->driver === $data['driver']);
        if ($data['driver'] !== 'log' && empty($data['credentials']) && !$credentialsStored) {
            abort(422, 'Credentials are required for real payment gateways.');
        }

        return $data;
    }

    private function audit(Request $request, string $action, $entity): void
    {
        AuditLog::create([
            'actor_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => $entity::class,
            'entity_id' => $entity->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);
    }

    private function ok($data, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }
}
