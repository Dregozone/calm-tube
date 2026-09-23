<?php

namespace App\Support;

use App\Enums\RefreshStatus;

/**
 * What one channel refresh achieved.
 *
 * A failure is reported rather than thrown, so a run over many channels never
 * stops because one of them was unreachable.
 *
 * Two counts, because they answer different questions. newVideos is how much
 * the archive grew and is what refresh_runs records. reachedFeed is how many
 * of those you will actually see, which on a Shorts heavy channel is often
 * none of them — and is the only number worth putting in front of a person,
 * because "2 new videos" followed by an unchanged feed reads as a bug.
 */
class RefreshResult
{
    public function __construct(
        public RefreshStatus $status,
        public int $newVideos = 0,
        public ?string $errorMessage = null,
        public int $reachedFeed = 0,
    ) {}

    public static function ok(int $newVideos, int $reachedFeed = 0): self
    {
        return new self(RefreshStatus::Ok, $newVideos, null, $reachedFeed);
    }

    /**
     * The feed worked, the API did not. Videos were stored without durations.
     */
    public static function degraded(int $newVideos, string $errorMessage, int $reachedFeed = 0): self
    {
        return new self(RefreshStatus::Degraded, $newVideos, $errorMessage, $reachedFeed);
    }

    public static function failed(string $errorMessage): self
    {
        return new self(RefreshStatus::Failed, 0, $errorMessage);
    }

    /**
     * Stored, but not something you will see: a Short, a stream still to
     * come, or an upload a channel's sample limit held back.
     */
    public function keptBack(): int
    {
        return max(0, $this->newVideos - $this->reachedFeed);
    }

    /**
     * What to tell a person, counting only what they will actually see and
     * accounting for whatever was stored but withheld.
     */
    public function summary(): string
    {
        $reached = $this->reachedFeed === 0
            ? __('No new videos')
            : $this->reachedFeed.' '.($this->reachedFeed === 1 ? __('new video') : __('new videos'));

        if ($this->keptBack() === 0) {
            return $reached.'.';
        }

        return $reached.'. '.($this->keptBack() === 1
            ? __('1 other upload was a Short or held back.')
            : __(':count other uploads were Shorts or held back.', ['count' => $this->keptBack()]));
    }

    /**
     * The same sentence for a run across many channels.
     *
     * @param  iterable<self>  $results
     */
    public static function summarise(iterable $results): string
    {
        $reached = 0;
        $keptBack = 0;
        $failed = 0;

        foreach ($results as $result) {
            if ($result->isFailed()) {
                $failed++;

                continue;
            }

            $reached += $result->reachedFeed;
            $keptBack += $result->keptBack();
        }

        $summary = (new self(RefreshStatus::Ok, $reached + $keptBack, null, $reached))->summary();

        if ($failed > 0) {
            $summary .= ' '.$failed.' '.
                ($failed === 1 ? __('channel') : __('channels')).' '.__('failed to refresh.');
        }

        return $summary;
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
