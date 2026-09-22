<?php

namespace App\Exceptions;

/**
 * A channel's RSS feed could not be fetched or understood.
 *
 * Isolated to one channel: a run over many channels carries on.
 */
class FeedUnavailableException extends YouTubeException
{
    public static function unreachable(string $channelId): self
    {
        return new self("Couldn't reach the feed for {$channelId}.");
    }

    public static function missing(string $channelId): self
    {
        return new self("This channel's feed no longer exists ({$channelId}).");
    }

    public static function unreadable(string $channelId): self
    {
        return new self("The feed for {$channelId} wasn't a YouTube feed.");
    }
}
