<?php

namespace App\Exceptions;

use Exception;

/**
 * Machine-readable OTP failure. $code is one of:
 *  expired | invalid | too_many_attempts | cooldown | rate_limited |
 *  not_found | already_verified | email_failed
 *
 * The controller maps $code to a JSON body + HTTP status; the message is
 * human-readable, the code is what clients branch on.
 */
class EmailOtpException extends Exception
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $data = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
