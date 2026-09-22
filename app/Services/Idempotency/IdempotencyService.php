<?php

namespace App\Services\Idempotency;

use App\Models\IdempotencyKey;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Run a money-moving (or otherwise non-repeatable) operation exactly once
 * per client-supplied idempotency key.
 *
 * A retry with the same key and identical parameters returns the stored
 * result instead of re-applying; the same key with different parameters is
 * rejected with an exception; a concurrent in-flight request with the same
 * key gets an "already being processed" error instead of double-applying.
 *
 * Extracted from WalletLedgerService::withIdempotency so campaign funding,
 * launches and any future non-ledger operations can share the same
 * guarantees (the ledger keeps its protected withIdempotency() as a
 * delegating wrapper for backward compatibility).
 */
class IdempotencyService
{
    /**
     * @param callable(): mixed $work
     * @param callable(IdempotencyKey): mixed|null $resolveResult custom replay resolver
     */
    public function run(
        ?string $key,
        string $action,
        ?int $userId,
        array $fingerprintParts,
        callable $work,
        ?callable $resolveResult = null
    ): mixed {
        if (empty($key)) {
            return $work();
        }

        $fingerprint = hash('sha256', $action . '|' . json_encode($fingerprintParts));

        return DB::transaction(function () use ($key, $action, $userId, $fingerprint, $work, $resolveResult) {
            try {
                $record = IdempotencyKey::create([
                    'idempotency_key' => $key,
                    'user_id' => $userId,
                    'action' => $action,
                    'fingerprint' => $fingerprint,
                ]);
            } catch (QueryException $e) {
                // Key already seen: only a genuine duplicate-key collision has a
                // row to show for it; anything else is rethrown untouched.
                $record = IdempotencyKey::where('idempotency_key', $key)->first();

                if (!$record) {
                    throw $e;
                }

                $record = IdempotencyKey::where('idempotency_key', $key)->lockForUpdate()->firstOrFail();

                if (!hash_equals((string) $record->fingerprint, $fingerprint)) {
                    throw new \Exception('Idempotency key was already used with different parameters.');
                }

                if ($record->result_type && $record->result_id) {
                    return $resolveResult ? $resolveResult($record) : $this->resolveStoredResult($record);
                }

                throw new \Exception('A request with this idempotency key is already being processed.');
            }

            $result = $work();

            if ($result === null || !is_object($result)) {
                // A null/scalar result cannot be replayed by id; roll the key
                // reservation back so the caller sees an honest failure
                // instead of a phantom success.
                throw new \Exception('Idempotent operations must return a model result for replay.');
            }

            $record->update([
                'result_type' => get_class($result),
                'result_id' => $result->getKey(),
            ]);

            return $result;
        });
    }

    /**
     * Default idempotency replay resolver: re-fetch the record that the
     * original request created.
     */
    protected function resolveStoredResult(IdempotencyKey $record): mixed
    {
        $class = $record->result_type;

        return $class::findOrFail($record->result_id);
    }

    /**
     * Pull the client key from the conventional header first, then the body.
     */
    public static function keyFromRequest(\Illuminate\Http\Request $request): ?string
    {
        $key = $request->header('Idempotency-Key') ?: $request->input('idempotency_key');

        return is_string($key) && $key !== '' ? $key : null;
    }
}
