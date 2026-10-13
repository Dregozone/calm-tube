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
    ) {}
}
