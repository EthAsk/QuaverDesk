<?php

namespace App\HubSpot;

use RuntimeException;

class HubSpotError extends RuntimeException
{
    /** Worth trying again later: HubSpot is busy or down (not a wrong token or missing scope). */
    public function retryable(): bool
    {
        return $this->getCode() === 429 || $this->getCode() >= 500 || $this->getCode() === 0;
    }
}
