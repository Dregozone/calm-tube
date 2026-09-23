<?php

use App\Jobs\RefreshChannel;
use App\Models\Channel;
use App\Models\Video;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

const OTHER_CHANNEL_ID = 'UCsecondchannelidabcdefg';

it('refreshes every enabled channel', function (): void {
    Storage::fake('local');
    fakeFeedSequence('feed-single-entry.xml', 'feed-other-single.xml');
    fakeVideosList();
    fakeThumbnailDownloads();
    calmChannel();
    Channel::factory()->create(['youtube_channel_id' => OTHER_CHANNEL_ID]);

    $this->artisan('calm:refresh')->assertSuccessful();

    expect(Video::count())->toBe(2);
});

it('does not refresh a disabled channel', function (): void {
    Storage::fake('local');
    fakeSuccessfulRefresh('feed-single-entry.xml', 'videos.list-single.json');
    Channel::factory()->disabled()->create(['youtube_channel_id' => CALM_CHANNEL_ID]);

    $this->artisan('calm:refresh')->assertSuccessful();

    expect(Video::count())->toBe(0);
});

it('refreshes only the requested channel', function (): void {
    Storage::fake('local');
    fakeSuccessfulRefresh('feed-single-entry.xml', 'videos.list-single.json');
    $wanted = calmChannel();
    $other = Channel::factory()->create(['youtube_channel_id' => OTHER_CHANNEL_ID]);

    $this->artisan('calm:refresh', ['--channel' => $wanted->youtube_channel_id])
        ->assertSuccessful();

    expect($wanted->videos()->count())->toBe(1)
        ->and($other->videos()->count())->toBe(0);
});

it('fails when the requested channel is not followed', function (): void {
    $this->artisan('calm:refresh', ['--channel' => 'UCnosuchchannelabcdefghi'])
        ->expectsOutputToContain('not found')
        ->assertFailed();
});

it('reports how many new videos it found', function (): void {
    Storage::fake('local');
    fakeSuccessfulRefresh('feed-single-entry.xml', 'videos.list-single.json');
    calmChannel();

    $this->artisan('calm:refresh')
        ->expectsOutputToContain('1 new video')
        ->assertSuccessful();
});

it('keeps refreshing after one channel fails', function (): void {
    Storage::fake('local');
    // No key, so the unreachable feed has no uploads playlist to fall back to.
    config()->set('calm-tube.api_key');
    calmChannel();
    Channel::factory()->create(['youtube_channel_id' => OTHER_CHANNEL_ID]);
    Http::fake([
        'www.youtube.com/feeds/videos.xml?channel_id='.CALM_CHANNEL_ID => Http::failedConnection(),
        'www.youtube.com/feeds/videos.xml*' => Http::response(youtubeFixture('feed-single-entry.xml')),
    ]);
    fakeShortsProbe();
    fakeThumbnailDownloads();

    $this->artisan('calm:refresh')->assertSuccessful();

    expect(Video::count())->toBe(1)
        ->and(Channel::where('youtube_channel_id', CALM_CHANNEL_ID)->sole()->last_refresh_error)
        ->not->toBeNull();
});

it('runs each refresh synchronously, so no queue worker is needed', function (): void {
    Bus::fake([RefreshChannel::class]);
    calmChannel();

    $this->artisan('calm:refresh')->assertSuccessful();

    // Had the command queued the job instead, nothing would have been
    // recorded as dispatched synchronously and this would fail.
    Bus::assertDispatchedSync(RefreshChannel::class);
});

it('does nothing when no channels are followed', function (): void {
    $this->artisan('calm:refresh')
        ->expectsOutputToContain('No channels')
        ->assertSuccessful();
});

it('is scheduled to run hourly', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'calm:refresh'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 * * * *');
});
