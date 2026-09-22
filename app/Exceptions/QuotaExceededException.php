<?php

namespace App\Exceptions;

/**
 * The Data API's daily allowance is gone until it resets at midnight Pacific.
 *
 * Enrichment stops, but everything discovered through the RSS feed is kept.
 */
class QuotaExceededException extends YouTubeException
{
    public static function make(): self
    {
        return new self("YouTube's API quota is used up for today. It resets at midnight Pacific.");
    }
}
