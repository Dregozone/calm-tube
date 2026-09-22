<?php

use App\Models\Video;
use App\Services\ChannelBackfiller;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->backfiller = app(ChannelBackfiller::class);
});

it('recovers uploads the feed can no longer reach', function (): void {
    fakeUploadsPlaylist();
    fakeVideosList('videos.list-empty.json');
    $channel = calmChannel(['uploads_playlist_id' => 'UUMOqf8ab-42UUQIdVoKwjlQ']);

    $recovered = $this->backfiller->backfill($channel, depth: 200);

    expect($recovered)->toHaveCount(80)
        ->and($channel->videos()->count())->toBe(80);
});

it('archives the title, description and publication date of a recovered video', function (): void {
    fakeUploadsPlaylist();
    fakeVideosList('videos.list-empty.json');
    $channel = calmChannel(['uploads_playlist_id' => 'UUMOqf8ab-42UUQIdVoKwjlQ']);

    $this->backfiller->backfill($channel, depth: 200);

    $video = Video::where('youtube_video_id', 'bkfilvid001')->sole();

    expect($video->title)->toBe('Backfilled Video 001')
        ->and($video->description)->toContain('Description for backfilled video 001.')
        ->and($video->published_at->toDateString())->toBe('2026-03-14')
        // The largest variant the response carried, taken as given.
        ->and($video->thumbnail_url)->toBe('https://i.ytimg.com/vi/bkfilvid001/maxresdefault.jpg');
});

it('uses the date the video was published, not the date it joined the playlist', function (): void {
    fakeUploadsPlaylist();
    fakeVideosList('videos.list-empty.json');
    $channel = calmChannel(['uploads_playlist_id' => 'UUMOqf8ab-42UUQIdVoKwjlQ']);

    $this->backfiller->backfill($channel, depth: 200);

    expect(Video::orderBy('published_at')->first()->youtube_video_id)->toBe('bkfilvid080');
});

it('stops once it has walked as deep as it was asked to', function (): void {
    fakeUploadsPlaylist();
    fakeVideosList('videos.list-empty.json');
    $channel = calmChannel(['uploads_playlist_id' => 'UUMOqf8ab-42UUQIdVoKwjlQ']);

    $this->backfiller->backfill($channel, depth: 50);

    expect($channel->videos()->count())->toBe(50)
        ->and(requestsTo('playlistItems'))->toBe(1);
});

it('does not store a video it already has', function (): void {
    fakeUploadsPlaylist();
    fakeVideosList('videos.list-empty.json');
    $channel = calmChannel(['uploads_playlist_id' => 'UUMOqf8ab-42UUQIdVoKwjlQ']);
    Video::factory()->for($channel)->create(['youtube_video_id' => 'bkfilvid001']);

    $recovered = $this->backfiller->backfill($channel, depth: 200);

    expect($recovered)->toHaveCount(79)
        ->and(Video::where('youtube_video_id', 'bkfilvid001')->count())->toBe(1);
});

it('stops at the first video it already has when closing a gap', function (): void {
    fakeUploadsPlaylist();
    fakeVideosList('videos.list-empty.json');
    $channel = calmChannel(['uploads_playlist_id' => 'UUMOqf8ab-42UUQIdVoKwjlQ']);
    Video::factory()->for($channel)->create(['youtube_video_id' => 'bkfilvid004']);

    $recovered = $this->backfiller->backfill($channel, depth: 200, untilKnown: true);

    // Walked back over 001 to 003, met something it already had, and did not
    // ask for the next page.
    expect($recovered)->toHaveCount(3)
        ->and(requestsTo('playlistItems'))->toBe(1);
});

it('looks up the uploads playlist when the channel has none stored', function (): void {
    fakeChannelsList();
    fakeUploadsPlaylist();
    fakeVideosList('videos.list-empty.json');
    $channel = calmChannel(['uploads_playlist_id' => null, 'handle' => null]);

    $this->backfiller->backfill($channel, depth: 50);

    expect($channel->fresh()->uploads_playlist_id)->toBe('UUMOqf8ab-42UUQIdVoKwjlQ')
        ->and($channel->fresh()->handle)->toBe('@practicalengineering');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/youtube/v3/channels'));
});

it('gives up quietly when the channel cannot be resolved', function (): void {
    fakeChannelsList('empty-items.json');
    $channel = calmChannel(['uploads_playlist_id' => null]);

    expect($this->backfiller->backfill($channel, depth: 50))->toBeEmpty();
});

it('enriches what it recovers', function (): void {
    fakeUploadsPlaylist('playlist-items-page2.json');
    fakeVideosList('videos.list-empty.json');
    $channel = calmChannel(['uploads_playlist_id' => 'UUMOqf8ab-42UUQIdVoKwjlQ']);

    $this->backfiller->backfill($channel, depth: 200);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/youtube/v3/videos'));
});

it('handles a channel whose uploads playlist is empty', function (): void {
    fakeUploadsPlaylist('playlist-items-empty.json');
    $channel = calmChannel(['uploads_playlist_id' => 'UUMOqf8ab-42UUQIdVoKwjlQ']);

    expect($this->backfiller->backfill($channel, depth: 200))->toBeEmpty();
});
