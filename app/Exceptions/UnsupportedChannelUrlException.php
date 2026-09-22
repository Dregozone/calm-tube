<?php

namespace App\Exceptions;

/**
 * An old /c/ custom URL. The Data API has no parameter for these, and the
 * alternatives are official, so the app asks for one of those instead.
 */
class UnsupportedChannelUrlException extends YouTubeException
{
    public static function legacyCustomUrl(): self
    {
        return new self(
            "YouTube's old /c/ links can't be resolved automatically. Open the channel ".
            'and copy its @handle, or paste a link to one of its videos.'
        );
    }
}
