<?php

namespace App\Exceptions;

/**
 * The input was understood, but YouTube has no such channel.
 */
class ChannelNotFoundException extends YouTubeException
{
    public static function for(string $input): self
    {
        return new self("No channel found for {$input}.");
    }
}
