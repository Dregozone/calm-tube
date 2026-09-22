<?php

namespace App\Exceptions;

/**
 * The Data API refused or failed a request for any reason other than quota,
 * such as a rejected key or an outage that outlasted the retries.
 */
class ApiRequestException extends YouTubeException
{
    public static function status(string $endpoint, int $status, ?string $reason = null): self
    {
        return new self(sprintf(
            'YouTube %s returned %d%s.',
            $endpoint,
            $status,
            $reason === null ? '' : " ({$reason})"
        ));
    }
}
