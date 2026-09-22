<?php

namespace Tests\Feature;

use App\Models\TaskSubmission;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Services\AI\ManualAIProvider;
use App\Services\AI\MockAIProvider;
use App\Services\Verification\VerificationService;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P0 honesty tests: mock AI results must be labelled as simulated, and
 * approved withdrawals must land in `processing` (log-only, funds NOT sent),
 * never `paid`.
 */
class HonestyLabelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_approve_withdrawal_sets_processing_not_paid(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'currency' => 'USD',
            'available_balance_cents' => 0,
            'pending_balance_cents' => 6000,
        ]);

        $withdrawal = WithdrawalRequest::create([
            'uuid' => (string) Str::uuid(),
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'amount_cents' => 6000,
            'currency' => 'USD',
            'payout_method' => 'bank_transfer',
            'payout_details_json' => ['iban' => 'TEST'],
            'status' => 'requested',
        ]);

        $processed = app(WalletLedgerService::class)->approveWithdrawal($withdrawal);

        $this->assertSame('processing', $processed->fresh()->status);
        $this->assertNotSame('paid', $processed->fresh()->status);
        $this->assertStringStartsWith('PAYLOG_', (string) $processed->fresh()->provider_transaction_id);
        $this->assertStringContainsString('funds not yet sent', (string) $processed->fresh()->admin_notes);
    }

    public function test_ai_provider_is_config_driven(): void
    {
        config(['verification.ai_provider' => 'mock']);
        $this->assertInstanceOf(MockAIProvider::class, $this->resolveProvider());
        $this->assertTrue(VerificationService::aiResultsAreSimulated());

        config(['verification.ai_provider' => 'manual']);
        $this->assertInstanceOf(ManualAIProvider::class, $this->resolveProvider());
        $this->assertFalse(VerificationService::aiResultsAreSimulated());
    }

    public function test_mock_ai_result_is_labelled_simulated(): void
    {
        config(['verification.ai_provider' => 'mock']);
        $result = $this->runPrecheck(new MockAIProvider());

        $this->assertTrue((bool) $result->ai_simulated);
        $this->assertSame('Simulated heuristic (pre-launch)', $result->ai_label);
        $this->assertStringNotContainsString('No duplicate image hash found', $result->analysis_summary);
    }

    public function test_manual_ai_provider_flags_for_human_review(): void
    {
        config(['verification.ai_provider' => 'manual']);
        $result = $this->runPrecheck(new ManualAIProvider());

        $this->assertFalse((bool) $result->ai_simulated);
        $this->assertSame('Manual review — no AI analysis', $result->ai_label);
        $this->assertSame('flag', $result->suggested_decision);
    }

    private function resolveProvider(): object
    {
        $method = new \ReflectionMethod(VerificationService::class, 'resolveAiProvider');

        return $method->invoke(null);
    }

    private function runPrecheck(object $provider): \App\Models\AiVerificationResult
    {
        DB::statement('PRAGMA foreign_keys = OFF');

        try {
            $user = User::factory()->create();

            $taskId = DB::table('tasks')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'campaign_id' => 999,
                'category_id' => 999,
                'title' => 'Honesty test task',
                'reward_cents' => 500,
                'slots_total' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $submissionId = DB::table('task_submissions')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'task_id' => $taskId,
                'user_id' => $user->id,
                'status' => 'submitted',
                'proof_data_json' => json_encode(['text_answer' => 'done']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $submission = TaskSubmission::findOrFail($submissionId);

            $service = new VerificationService($provider);

            return $service->processNewSubmission($submission);
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }
}
