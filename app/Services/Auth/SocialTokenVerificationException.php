<?php

namespace App\Services\Auth;

class SocialTokenVerificationException extends \RuntimeException
{
    // Deliberately message-less by default: callers answer a generic 401 and
    // the detail stays in the logs (token content must never leak to clients).
}
