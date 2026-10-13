<?php

namespace App\Support;

/**
 * One item on Personal Assistant's reading list, as its API describes it.
 */
class ReadingListEntry
{
    public function __construct(
        public int $id,
        public string $title,
        public ?string $url,
        public ?string $category,
        public int $percentComplete,
        public ?string $notes = null,
        public int $important = 0,
        public int $urgent = 0,
        // Minutes, as Personal Assistant keeps it.
        public ?int $estimatedDuration = null,
    ) {}
}
