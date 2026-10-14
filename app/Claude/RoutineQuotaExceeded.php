<?php

namespace App\Claude;

use RuntimeException;

class RoutineQuotaExceeded extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Routine daily quota exceeded.');
    }
}
