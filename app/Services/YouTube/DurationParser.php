<?php

namespace App\Services\YouTube;

use DateInterval;
use Exception;

/**
 * Converts YouTube's ISO 8601 durations into seconds, and seconds into
 * something readable on a card.
 */
class DurationParser
{
    /**
     * Returns null for anything unusable, including the "P0D" that a stream
     * still in progress reports.
     */
    public function toSeconds(?string $iso): ?int
    {
        if ($iso === null || $iso === '') {
            return null;
        }

        try {
            $interval = new DateInterval($iso);
        } catch (Exception) {
            return null;
        }

        $seconds = ($interval->d * 86400)
            + ($interval->h * 3600)
            + ($interval->i * 60)
            + $interval->s;

        return $seconds > 0 ? $seconds : null;
    }

    /**
     * M:SS under an hour, H:MM:SS at an hour or more.
     */
    public function toHuman(?int $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }

        if ($seconds >= 3600) {
            return sprintf(
                '%d:%02d:%02d',
                intdiv($seconds, 3600),
                intdiv($seconds % 3600, 60),
                $seconds % 60
            );
        }

        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
