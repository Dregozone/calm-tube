<?php

use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Every feature test runs with outbound HTTP blocked. Each test fakes only the
| endpoints it expects to be called, so an unexpected request to YouTube fails
| the test instead of silently succeeding against a catch-all stub.
|
*/

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->beforeEach(function (): void {
        Http::preventStrayRequests();

        config()->set('calm-tube.api_key', 'test-api-key');
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Fixtures
|--------------------------------------------------------------------------
*/

const CALM_CHANNEL_ID = 'UCMOqf8ab-42UUQIdVoKwjlQ';

const CALM_CHANNEL_TITLE = 'Practical Engineering';

/** The newest video in feed.xml and feed-single-entry.xml. */
const CALM_VIDEO_ID = 'calmvid0001';

function youtubeFixture(string $name): string
{
    return file_get_contents(__DIR__."/Fixtures/youtube/{$name}");
}

/*
|--------------------------------------------------------------------------
| Endpoint Fakes
|--------------------------------------------------------------------------
|
| One helper per YouTube endpoint. Call only the ones a test expects to be
| used; anything else hits preventStrayRequests() and fails loudly.
|
*/

/**
 * @param  array<string, string>  $headers
 */
function fakeFeed(string $fixture = 'feed.xml', int $status = 200, array $headers = []): void
{
    Http::fake([
        'www.youtube.com/feeds/videos.xml*' => Http::response(
            $status === 304 ? '' : youtubeFixture($fixture),
            $status,
            $headers,
        ),
    ]);
}

function fakeFeedFailure(): void
{
    Http::fake([
        'www.youtube.com/feeds/videos.xml*' => Http::failedConnection(),
    ]);
}

/**
 * Serves a different feed to each successive request, for tests that refresh
 * the same channel twice. A second call to fakeFeed() would be shadowed by the
 * first, because the HTTP fake resolves the earliest matching stub.
 */
function fakeFeedSequence(string ...$fixtures): void
{
    $sequence = Http::sequence();

    foreach ($fixtures as $fixture) {
        $sequence->push(youtubeFixture($fixture), 200);
    }

    Http::fake([
        'www.youtube.com/feeds/videos.xml*' => $sequence,
    ]);
}

function fakeVideosList(string $fixture = 'videos.list.json', int $status = 200): void
{
    Http::fake([
        'www.googleapis.com/youtube/v3/videos*' => Http::response(youtubeFixture($fixture), $status),
    ]);
}

function fakeChannelsList(string $fixture = 'channels.list.json', int $status = 200): void
{
    Http::fake([
        'www.googleapis.com/youtube/v3/channels*' => Http::response(youtubeFixture($fixture), $status),
    ]);
}

/**
 * A Short answers 200; anything else redirects to the watch page.
 */
function fakeShortsProbe(int $status = 303): void
{
    Http::fake([
        'www.youtube.com/shorts/*' => Http::response('', $status, $status === 303
            ? ['Location' => 'https://www.youtube.com/watch?v='.CALM_VIDEO_ID]
            : []),
    ]);
}

function fakeShortsProbeFailure(): void
{
    Http::fake([
        'www.youtube.com/shorts/*' => Http::failedConnection(),
    ]);
}

function fakeThumbnailDownloads(string $fixture = 'thumbnail.jpg'): void
{
    Http::fake([
        'i.ytimg.com/*' => Http::response(youtubeFixture($fixture), 200, ['Content-Type' => 'image/jpeg']),
        'i4.ytimg.com/*' => Http::response(youtubeFixture($fixture), 200, ['Content-Type' => 'image/jpeg']),
        'yt3.ggpht.com/*' => Http::response(youtubeFixture($fixture), 200, ['Content-Type' => 'image/jpeg']),
    ]);
}

/**
 * maxresdefault is missing for older uploads; mqdefault always exists.
 */
function fakeThumbnailDownloadsWithoutMaxres(): void
{
    Http::fake([
        'i.ytimg.com/*/maxresdefault.jpg' => Http::response('', 404),
        'i.ytimg.com/*' => Http::response(youtubeFixture('thumbnail.jpg'), 200, ['Content-Type' => 'image/jpeg']),
    ]);
}

/**
 * Fakes every YouTube endpoint a full, successful refresh touches.
 */
function fakeSuccessfulRefresh(string $feedFixture = 'feed.xml', string $videosFixture = 'videos.list.json'): void
{
    fakeFeed($feedFixture);
    fakeVideosList($videosFixture);
    fakeThumbnailDownloads();
}

/*
|--------------------------------------------------------------------------
| Model Helpers
|--------------------------------------------------------------------------
*/

/**
 * The channel the committed feed fixtures belong to.
 *
 * @param  array<string, mixed>  $attributes
 */
function calmChannel(array $attributes = []): Channel
{
    return Channel::factory()->create([
        'youtube_channel_id' => CALM_CHANNEL_ID,
        'title' => CALM_CHANNEL_TITLE,
        ...$attributes,
    ]);
}

function calmUser(): User
{
    return User::factory()->create();
}
