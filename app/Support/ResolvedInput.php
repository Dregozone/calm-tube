<?php

namespace App\Support;

use App\Enums\ChannelInputType;

/**
 * The outcome of parsing pasted input, with no network call made yet.
 */
class ResolvedInput
{
    public function __construct(
        public ChannelInputType $type,
        public string $value,
    ) {}
}
