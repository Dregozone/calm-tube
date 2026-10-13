<?php

namespace App\Services\ReadingList;

use App\Exceptions\ReadingListException;
use App\Models\ReadingListCourse;
use App\Models\ReadingListItem;
use App\Models\Video;
use App\Support\ReadingListEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Keeps Calm Tube's copy of the reading list's YouTube videos and courses,
 * and writes finishing a video back to Personal Assistant.
 *
 * The copy is what the videos and courses pages read, so they work whether
 * or not Personal Assistant answers. A failed refresh leaves it exactly as it
 * was.
 */
class ReadingListSync
{
    private const string REFRESHED_AT_KEY = 'calm-tube.reading-list.refreshed_at';

    public function __construct(
        private readonly ReadingListClient $client,
        private readonly VideoUrlParser $urls,
        private readonly ReadingListArchiver $archiver,
    ) {}

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * Replaces the copy with the list as it is now, keeping only YouTube
     * videos and courses, then archives any video not seen before.
     *
     * @throws ReadingListException
     */
    public function refresh(): void
    {
        $rows = [];
        $courses = [];

        foreach ($this->client->items() as $position => $entry) {
            $videoId = $this->urls->videoId($entry->url);

            if ($videoId !== null) {
                $rows[] = $this->toRow($entry, $videoId, $position);
            }

            if (ReadingListCourse::isCourse($entry->category)) {
                $courses[] = $this->toCourseRow($entry, $position);
            }
        }

        DB::transaction(function () use ($rows, $courses): void {
            $this->replace(ReadingListItem::class, $rows);
            $this->replace(ReadingListCourse::class, $courses);
        });

        Cache::forever(self::REFRESHED_AT_KEY, now()->toIso8601String());

        /** @var list<string> $pending */
        $pending = ReadingListItem::query()->pending()->pluck('youtube_video_id')->unique()->values()->all();

        $this->archiver->archive($pending);

        // Every video on the list, done or not, and whether the reading list
        // or the feed found it first: from now on it is the videos page's.
        Video::query()
            ->whereIn('youtube_video_id', ReadingListItem::query()->select('youtube_video_id'))
            ->whereNull('on_reading_list_at')
            ->update(['on_reading_list_at' => now()]);
    }

    public function lastRefreshedAt(): ?CarbonImmutable
    {
        $stored = Cache::get(self::REFRESHED_AT_KEY);

        return is_string($stored) ? CarbonImmutable::instance(Date::parse($stored)) : null;
    }

    /**
     * Never refreshed, or not for longer than the configured window.
     */
    public function isStale(): bool
    {
        $last = $this->lastRefreshedAt();

        return ! $last instanceof CarbonImmutable
            || $last->lt(now()->subHours((int) config('calm-tube.reading_list.refresh_after_hours')));
    }

    /**
     * Moves the item to the done mark in Personal Assistant, which is as far
     * as anything but Personal Assistant may take it, and so off the videos
     * page. If Personal Assistant does not take it, nothing changes here
     * either.
     *
     * @throws ReadingListException
     */
    public function markWatched(ReadingListItem $item): void
    {
        $percent = (int) config('calm-tube.reading_list.done_percent');

        $this->client->setProgress($item->reading_list_id, $percent);

        $item->forceFill(['percent_complete' => $percent])->save();
    }

    /**
     * @param  class-string<ReadingListItem|ReadingListCourse>  $model
     * @param  list<array<string, mixed>>  $rows
     */
    private function replace(string $model, array $rows): void
    {
        $model::query()
            ->whereNotIn('reading_list_id', array_column($rows, 'reading_list_id'))
            ->delete();

        foreach ($rows as $row) {
            $model::query()->updateOrCreate(
                ['reading_list_id' => $row['reading_list_id']],
                $row,
            );
        }
    }

    /**
     * @return array{reading_list_id: int, youtube_video_id: string, title: string, url: string, category: string|null, percent_complete: int, position: int}
     */
    private function toRow(ReadingListEntry $entry, string $videoId, int $position): array
    {
        return [
            'reading_list_id' => $entry->id,
            'youtube_video_id' => $videoId,
            'title' => $entry->title,
            'url' => (string) $entry->url,
            'category' => $entry->category,
            'percent_complete' => max(0, min(100, $entry->percentComplete)),
            'position' => $position,
        ];
    }

    /**
     * @return array{reading_list_id: int, title: string, url: string|null, notes: string|null, important: int, urgent: int, estimated_minutes: int|null, percent_complete: int, position: int}
     */
    private function toCourseRow(ReadingListEntry $entry, int $position): array
    {
        return [
            'reading_list_id' => $entry->id,
            'title' => $entry->title,
            'url' => $entry->url,
            'notes' => $entry->notes,
            'important' => max(0, min(255, $entry->important)),
            'urgent' => max(0, min(255, $entry->urgent)),
            'estimated_minutes' => $entry->estimatedDuration,
            'percent_complete' => max(0, min(100, $entry->percentComplete)),
            'position' => $position,
        ];
    }
}
