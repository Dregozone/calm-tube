<?php

use App\Models\Channel;
use App\Models\Video;
use Illuminate\Support\Facades\Http;

it('recovers older uploads for every enabled channel', function (): void {
    fakeUploadsPlaylist();
    fakeVideosList('videos.list-empty.json');
    calmChannel(['uploads_playlist_id' => 'UUMOqf8ab-42UUQIdVoKwjlQ']);

    $this->artisan('calm:backfill')
        ->expectsOutputToContain('Recovered 80 videos')
        ->assertSuccessful();

    expect(Video::count())->toBe(80);
});

it('does not backfill a disabled channel', function (): void {
    Channel::factory()->disabled()->create(['uploads_playlist_id' => 'UUMOqf8ab-42UUQIdVoKwjlQ']);

    $this->artisan('calm:backfill')->assertSuccessful();

    expect(Video::count())->toBe(0);
    Http::assertNothingSent();
});

it('walks only as deep as asked', function (): void {
    fakeUploadsPlaylist();
    fakeVideosList('videos.list-empty.json');
    calmChannel(['uploads_playlist_id' => 'UUMOqf8ab-42UUQIdVoKwjlQ']);

    $this->artisan('calm:backfill', ['--depth' => 50])->assertSuccessful();

    expect(Video::count())->toBe(50);
});

it('backfills only the requested channel', function (): void {
    fakeUploadsPlaylist();
    fakeVideosList('videos.list-empty.json');
    $wanted = calmChannel(['uploads_playlist_id' => 'UUMOqf8ab-42UUQIdVoKwjlQ']);
    $other = Channel::factory()->create(['uploads_playlist_id' => 'UUotherchannelabcdefghi']);

    $this->artisan('calm:backfill', ['--channel' => $wanted->youtube_channel_id])
        ->assertSuccessful();

    expect($wanted->videos()->count())->toBe(80)
        ->and($other->videos()->count())->toBe(0);
});

it('fails when the requested channel is not followed', function (): void {
    $this->artisan('calm:backfill', ['--channel' => 'UCnosuchchannelabcdefghi'])
        ->expectsOutputToContain('not found')
        ->assertFailed();
});

it('refuses to run without an API key', function (): void {
    config()->set('calm-tube.api_key');
    calmChannel();

    $this->artisan('calm:backfill')
        ->expectsOutputToContain('API key')
        ->assertFailed();

    Http::assertNothingSent();
});

it('does nothing when no channels are followed', function (): void {
    $this->artisan('calm:backfill')
        ->expectsOutputToContain('No channels')
        ->assertSuccessful();
});
