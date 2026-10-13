<?php

namespace App\Exceptions;

use Exception;

/**
 * Personal Assistant could not be asked about the reading list: it is not
 * configured, it could not be reached, or it refused.
 *
 * Callers catch this and carry on with the copy they already have.
 */
class ReadingListException extends Exception
{
    public static function notConfigured(): self
    {
        return new self('Personal Assistant is not set up. Add READING_LIST_URL, READING_LIST_IDENTIFIER and READING_LIST_TOKEN to your .env.');
    }

    public static function unreachable(): self
    {
        return new self("Couldn't reach Personal Assistant. Showing the list as it was last time.");
    }

    public static function status(int $status): self
    {
        return new self("Personal Assistant answered {$status}. Showing the list as it was last time.");
    }
}
