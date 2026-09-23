<?php

namespace App\Support;

/**
 * A single video as YouTube's oEmbed endpoint describes it.
 */
class EmbedData
{
    public function __construct(
        public string $videoId,
        public string $title,
        public ?string $authorName = null,
        public ?string $thumbnailUrl = null,
    ) {}
}
