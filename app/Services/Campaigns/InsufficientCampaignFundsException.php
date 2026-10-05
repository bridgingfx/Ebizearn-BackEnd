<?php

namespace App\Services\Campaigns;

use Exception;

/**
 * Thrown when the target business wallet cannot cover a campaign's
 * rewards budget + platform fee. Carries the honest numbers so callers
 * can name the shortfall instead of returning a generic error.
 */
class InsufficientCampaignFundsException extends Exception
{
    public function __construct(
        public readonly int $availableCents,
        public readonly int $requiredCents,
        ?string $message = null
    ) {
        parent::__construct(
            $message ?? 'Insufficient available balance for campaign funding.',
            422
        );
    }
}
