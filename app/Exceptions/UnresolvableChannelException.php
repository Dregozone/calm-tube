<?php

namespace App\Exceptions;

/**
 * The pasted input could not be turned into a channel to follow.
 */
class UnresolvableChannelException extends YouTubeException
{
    public static function cannotParse(): self
    {
        return new self(
            "That doesn't look like a YouTube channel. Try an @handle, a channel URL, ".
            'or a link to one of its videos.'
        );
    }

    /**
     * Handles, legacy usernames and video links all need a lookup. A full
     * /channel/UC… URL does not, because the feed can be read directly.
     */
    public static function needsApiKey(): self
    {
        return new self(
            'Adding a channel this way needs a YouTube API key. Add YOUTUBE_API_KEY to '.
            'your .env, or paste a full /channel/UC… URL, which works without one.'
        );
    }
}
