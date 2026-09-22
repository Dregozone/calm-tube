<?php

use App\Enums\LiveStatus;
use App\Models\Video;
use Illuminate\Support\Facades\Http;

it('fills in the duration for a video that was never enriched', function (): void {
    fakeVideosList('videos.list-live.json');
    $video = Video::factory()->for(calmChannel())->unenriched()->create([
        'youtube_video_id' => 'calmplain01',
    ]);

    $this->artisan('calm:enrich')->assertSuccessful();

    expect($video->fresh()->duration_seconds)->toBe(724)
        ->and($video->fresh()->enriched_at)->not->toBeNull();
});

it('re-checks a video that was live or upcoming last time', function (string $state, string $videoId): void {
    fakeVideosList('videos.list-live.json');
    $video = Video::factory()->for(calmChannel())->{$state}()->create([
        'youtube_video_id' => $videoId,
    ]);

    $this->artisan('calm:enrich')->assertSuccessful();

    expect($video->fresh()->live_status)->toBe(LiveStatus::None);
})->with([
    'a stream that has now finished' => ['live', 'calmdone001'],
    'a premiere that has now aired' => ['upcoming', 'calmdone001'],
]);

it('gives a finished stream its duration and lets it into the feed', function (): void {
    fakeVideosList('videos.list-live.json');
    $video = Video::factory()->for(calmChannel())->live()->create([
        'youtube_video_id' => 'calmdone001',
    ]);

    $this->artisan('calm:enrich')->assertSuccessful();

    expect($video->fresh()->duration_seconds)->toBe(4442)
        ->and(Video::inFeed()->pluck('id')->all())->toContain($video->id);
});

it('leaves a stream that is still running as live', function (): void {
    fakeVideosList('videos.list-live.json');
    $video = Video::factory()->for(calmChannel())->live()->create([
        'youtube_video_id' => 'calmlive001',
    ]);

    $this->artisan('calm:enrich')->assertSuccessful();

    expect($video->fresh()->live_status)->toBe(LiveStatus::Live)
        ->and(Video::inFeed()->count())->toBe(0);
});

it('never changes an archived title or description', function (): void {
    fakeVideosList('videos.list-live.json');
    $video = Video::factory()->for(calmChannel())->unenriched()->create([
        'youtube_video_id' => 'calmplain01',
        'title' => 'The Title I Archived',
        'description' => 'The description I archived.',
    ]);

    $this->artisan('calm:enrich')->assertSuccessful();

    expect($video->fresh()->title)->toBe('The Title I Archived')
        ->and($video->fresh()->description)->toBe('The description I archived.');
});

it('marks a video unavailable when YouTube no longer returns it', function (): void {
    fakeVideosList('videos.list-empty.json');
    $video = Video::factory()->for(calmChannel())->unenriched()->create();

    $this->artisan('calm:enrich')->assertSuccessful();

    expect($video->fresh()->unavailable_at)->not->toBeNull();
});

it('leaves an enriched video alone', function (): void {
    Video::factory()->for(calmChannel())->create(['enriched_at' => now()]);

    $this->artisan('calm:enrich')->assertSuccessful();

    Http::assertNothingSent();
});

it('does nothing when no API key is configured', function (): void {
    config()->set('calm-tube.api_key');
    Video::factory()->for(calmChannel())->unenriched()->create();

    $this->artisan('calm:enrich')
        ->expectsOutputToContain('API key')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('batches its lookups 50 at a time', function (): void {
    fakeVideosList('videos.list-empty.json');
    Video::factory()->for(calmChannel())->unenriched()->count(60)->create();

    $this->artisan('calm:enrich')->assertSuccessful();

    Http::assertSentCount(2);
});
