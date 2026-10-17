<?php

namespace App\Logging;

use Illuminate\Log\Logger;

/** Tap channel log Laravel: pasang RedactSensitiveData pada semua handler channel. */
class RedactSensitiveLogs
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(new RedactSensitiveData);
    }
}
