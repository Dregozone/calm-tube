<?php

use App\Models\Channel;
use App\Models\Video;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('images');
});

it('archives thumbnails that are not stored yet', function (): void {
    fakeThumbnailDownloads();
    $video = Video::factory()->for(calmChannel(['avatar_url' => null]))->create(['thumbnail_path' => null]);

    $this->artisan('calm:archive')
        ->expectsOutputToContain('Archived 1 image')
        ->assertSuccessful();

    expect($video->fresh()->thumbnail_path)->not->toBeNull();
    Storage::disk('images')->assertExists($video->fresh()->thumbnail_path);
});

it('leaves a video it has already archived alone', function (): void {
    Video::factory()->for(calmChannel(['avatar_url' => null]))->archived()->create();

    $this->artisan('calm:archive')->assertSuccessful();

    Http::assertNothingSent();
});

it('never archives a Short, because the feed never shows one', function (): void {
    fakeThumbnailDownloads();
    $channel = calmChannel(['avatar_url' => null]);
    Video::factory()->for($channel)->short()->create(['thumbnail_path' => null]);

    $this->artisan('calm:archive')->assertSuccessful();

    Http::assertNothingSent();
});

it('does not archive a video that is gone from YouTube', function (): void {
    $channel = calmChannel(['avatar_url' => null]);
    Video::factory()->for($channel)->unavailable()->create(['thumbnail_path' => null]);

    $this->artisan('calm:archive')->assertSuccessful();

    Http::assertNothingSent();
});

it('archives channel avatars too', function (): void {
    fakeThumbnailDownloads();
    $channel = calmChannel([
        'avatar_url' => 'https://yt3.ggpht.com/calm-avatar=s800',
        'avatar_path' => null,
    ]);

    $this->artisan('calm:archive')->assertSuccessful();

    expect($channel->fresh()->avatar_path)->toBe('calm-tube/avatars/'.CALM_CHANNEL_ID.'.jpg');
});

it('reports what it could not download, so it can be retried', function (): void {
    Http::fake(['*.ytimg.com/*' => Http::response('', 404)]);
    Video::factory()->for(calmChannel(['avatar_url' => null]))->create(['thumbnail_path' => null]);

    $this->artisan('calm:archive')
        ->expectsOutputToContain('1 could not be downloaded')
        ->assertSuccessful();
});

it('stops at the limit it was given', function (): void {
    fakeThumbnailDownloads();
    Video::factory()->for(calmChannel(['avatar_url' => null]))->count(5)->create(['thumbnail_path' => null]);

    $this->artisan('calm:archive', ['--limit' => 2])->assertSuccessful();

    expect(Video::whereNotNull('thumbnail_path')->count())->toBe(2);
});

it('leaves a channel with no avatar url alone', function (): void {
    Channel::factory()->create(['avatar_url' => null, 'avatar_path' => null]);

    $this->artisan('calm:archive')->assertSuccessful();

    Http::assertNothingSent();
});

it('counts an avatar it could not download, rather than passing over it', function (): void {
    Http::fake(['*.ggpht.com/*' => Http::failedConnection()]);
    calmChannel([
        'avatar_url' => 'https://yt3.ggpht.com/calm-avatar=s800',
        'avatar_path' => null,
    ]);

    $this->artisan('calm:archive')
        ->expectsOutputToContain('1 could not be downloaded')
        ->assertSuccessful();
});
