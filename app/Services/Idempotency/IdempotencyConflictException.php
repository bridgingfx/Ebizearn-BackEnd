<?php

namespace App\Services\Idempotency;

/**
 * An idempotency key was replayed in a way that must not re-apply the
 * operation: either the key is already being processed by a concurrent
 * request, or it was already used with different parameters. Controllers
 * map this to HTTP 409 (Conflict) rather than 400.
 */
class IdempotencyConflictException extends \Exception
{
}
