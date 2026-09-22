<?php

use App\Enums\LiveStatus;
use App\Models\Channel;
use App\Models\RefreshRun;
use App\Models\Video;
use Illuminate\Support\Carbon;

it('orders the feed by publication date, newest first', function (): void {
    $channel = Channel::factory()->create();
    Video::factory()->for($channel)->create(['published_at' => '2026-03-01 12:00:00']);
    Video::factory()->for($channel)->create(['published_at' => '2026-03-14 12:00:00']);
    Video::factory()->for($channel)->create(['published_at' => '2026-03-07 12:00:00']);

    $published = Video::inFeed()->orderByDesc('published_at')->pluck('published_at');

    expect($published->map->toDateString()->all())
        ->toBe(['2026-03-14', '2026-03-07', '2026-03-01']);
});

it('keeps a video out of the feed when it is hidden, a Short, live, upcoming or unavailable', function (string $state): void {
    $video = Video::factory()->for(Channel::factory())->{$state}()->create();

    expect(Video::inFeed()->pluck('id')->all())->not->toContain($video->id);
})->with(['hidden', 'short', 'live', 'upcoming', 'unavailable']);

it('keeps a video out of the feed when its channel is disabled', function (): void {
    $video = Video::factory()->for(Channel::factory()->disabled())->create();

    expect(Video::inFeed()->pluck('id')->all())->not->toContain($video->id);
});

it('includes a video in the feed when Shorts detection could not decide', function (): void {
    $video = Video::factory()->for(Channel::factory())->create(['is_short' => null]);

    expect(Video::inFeed()->pluck('id')->all())->toContain($video->id);
});

it('includes a watched video in the feed', function (): void {
    $video = Video::factory()->for(Channel::factory())->watched()->create();

    expect(Video::inFeed()->pluck('id')->all())->toContain($video->id);
});

it('includes a video that has no duration yet', function (): void {
    $video = Video::factory()->for(Channel::factory())->unenriched()->create();

    expect(Video::inFeed()->pluck('id')->all())->toContain($video->id);
});

it('limits the unwatched scope to videos with no watched timestamp', function (): void {
    $channel = Channel::factory()->create();
    $unwatched = Video::factory()->for($channel)->create();
    $watched = Video::factory()->for($channel)->watched()->create();

    $ids = Video::unwatched()->pluck('id')->all();

    expect($ids)->toContain($unwatched->id)
        ->and($ids)->not->toContain($watched->id);
});

it('uses the YouTube video id as its route key', function (): void {
    $video = Video::factory()->for(Channel::factory())->create([
        'youtube_video_id' => CALM_VIDEO_ID,
    ]);

    expect($video->getRouteKeyName())->toBe('youtube_video_id')
        ->and($video->getRouteKey())->toBe(CALM_VIDEO_ID);
});

it('casts its state columns', function (): void {
    $video = Video::factory()->for(Channel::factory())->live()->create();

    expect($video->live_status)->toBe(LiveStatus::Live)
        ->and($video->published_at)->toBeInstanceOf(Carbon::class);
});

it('formats its duration for display', function (?int $seconds, ?string $expected): void {
    $video = Video::factory()->for(Channel::factory())->create(['duration_seconds' => $seconds]);

    expect($video->duration_for_humans)->toBe($expected);
})->with([
    'under an hour' => [724, '12:04'],
    'over an hour' => [3723, '1:02:03'],
    'unknown' => [null, null],
]);

it('reports whether it has been watched', function (): void {
    $channel = Channel::factory()->create();

    expect(Video::factory()->for($channel)->watched()->create()->isWatched())->toBeTrue()
        ->and(Video::factory()->for($channel)->create()->isWatched())->toBeFalse();
});

it('deletes its videos and refresh runs when the channel is deleted', function (): void {
    $channel = Channel::factory()->create();
    $video = Video::factory()->for($channel)->create();
    $run = RefreshRun::factory()->for($channel)->create();

    $keptChannel = Channel::factory()->create();
    $keptVideo = Video::factory()->for($keptChannel)->create();

    $channel->delete();

    $this->assertModelMissing($video);
    $this->assertModelMissing($run);
    $this->assertModelExists($keptVideo);
});
