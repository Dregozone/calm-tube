<?php

namespace App\Support;

use App\Enums\RefreshStatus;

/**
 * What one channel refresh achieved.
 *
 * A failure is reported rather than thrown, so a run over many channels never
 * stops because one of them was unreachable.
 */
class RefreshResult
{
    public function __construct(
        public RefreshStatus $status,
        public int $newVideos = 0,
        public ?string $errorMessage = null,
    ) {}

    public static function ok(int $newVideos): self
    {
        return new self(RefreshStatus::Ok, $newVideos);
    }

    /**
     * The feed worked, the API did not. Videos were stored without durations.
     */
    public static function degraded(int $newVideos, string $errorMessage): self
    {
        return new self(RefreshStatus::Degraded, $newVideos, $errorMessage);
    }

    public static function failed(string $errorMessage): self
    {
        return new self(RefreshStatus::Failed, 0, $errorMessage);
    }

    public function isFailed(): bool
    {
        return $this->status === RefreshStatus::Failed;
    }

    public function isDegraded(): bool
    {
        return $this->status === RefreshStatus::Degraded;
    }
}
