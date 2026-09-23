<?php

namespace App\Exceptions;

/**
 * A single video could not be looked up, either because YouTube has no
 * playable video by that id or because YouTube could not be reached.
 */
class VideoLookupException extends YouTubeException
{
    public static function notPlayable(string $videoId): self
    {
        return new self("YouTube has no video {$videoId} that can be played here. It may be private, removed, or not allowed to be embedded.");
    }

    public static function unreachable(string $videoId): self
    {
        return new self("Couldn't reach YouTube to look up {$videoId}. Try again in a moment.");
    }
}
