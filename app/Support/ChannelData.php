<?php

namespace App\Support;

/**
 * A channel as YouTube describes it, before it becomes a Channel model.
 */
class ChannelData
{
    public function __construct(
        public string $channelId,
        public string $title,
        public ?string $handle = null,
        public ?string $avatarUrl = null,
        public ?string $uploadsPlaylistId = null,
    ) {}
}
