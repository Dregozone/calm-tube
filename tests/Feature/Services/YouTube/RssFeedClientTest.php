<?php

use App\Exceptions\FeedUnavailableException;
use App\Services\YouTube\RssFeedClient;
use App\Support\FeedEntry;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->client = app(RssFeedClient::class);
});

it('requests the feed for the given channel', function (): void {
    fakeFeed('feed-single-entry.xml');

    $this->client->fetch(CALM_CHANNEL_ID);

    Http::assertSent(fn (Request $request): bool => $request->url() ===
        'https://www.youtube.com/feeds/videos.xml?channel_id='.CALM_CHANNEL_ID);
});

it('parses every entry in the feed', function (): void {
    fakeFeed();

    $response = $this->client->fetch(CALM_CHANNEL_ID);

    expect($response->entries)->toHaveCount(15)
        ->and($response->entries[0])->toBeInstanceOf(FeedEntry::class);
});

it('parses the fields it archives from an entry', function (): void {
    fakeFeed('feed-single-entry.xml');

    $entry = $this->client->fetch(CALM_CHANNEL_ID)->entries[0];

    expect($entry->videoId)->toBe(CALM_VIDEO_ID)
        ->and($entry->title)->toBe('Calm Tube Test Video 01')
        ->and($entry->description)->toContain('Description for Calm Tube Test Video 01.')
        ->and($entry->publishedAt->toIso8601String())->toBe('2026-03-15T14:00:00+00:00')
        ->and($entry->thumbnailUrl)->toBe('https://i4.ytimg.com/vi/calmvid0001/hqdefault.jpg');
});

it('returns the entries newest first', function (): void {
    fakeFeed();

    $entries = $this->client->fetch(CALM_CHANNEL_ID)->entries;

    expect($entries[0]->videoId)->toBe('calmvid0001')
        ->and($entries[14]->videoId)->toBe('calmvid0015')
        ->and($entries[0]->publishedAt->isAfter($entries[14]->publishedAt))->toBeTrue();
});

it('returns the channel title so a channel can be named without the API', function (): void {
    fakeFeed('feed-single-entry.xml');

    expect($this->client->fetch(CALM_CHANNEL_ID)->channelTitle)->toBe(CALM_CHANNEL_TITLE);
});

it('handles a feed with no uploads yet', function (): void {
    fakeFeed('feed-empty.xml');

    $response = $this->client->fetch(CALM_CHANNEL_ID);

    expect($response->entries)->toBeEmpty()
        ->and($response->notModified)->toBeFalse();
});

describe('conditional requests', function (): void {
    it('sends the stored validators', function (): void {
        fakeFeed('feed-single-entry.xml');

        $this->client->fetch(CALM_CHANNEL_ID, '"abc123"', 'Sun, 15 Mar 2026 14:00:00 GMT');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('If-None-Match', '"abc123"')
            && $request->hasHeader('If-Modified-Since', 'Sun, 15 Mar 2026 14:00:00 GMT'));
    });

    it('sends no validators when none are stored', function (): void {
        fakeFeed('feed-single-entry.xml');

        $this->client->fetch(CALM_CHANNEL_ID);

        Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('If-None-Match')
            && ! $request->hasHeader('If-Modified-Since'));
    });

    it('returns the validators from the response so they can be stored', function (): void {
        fakeFeed('feed-single-entry.xml', 200, [
            'ETag' => '"abc123"',
            'Last-Modified' => 'Sun, 15 Mar 2026 14:00:00 GMT',
        ]);

        $response = $this->client->fetch(CALM_CHANNEL_ID);

        expect($response->etag)->toBe('"abc123"')
            ->and($response->lastModified)->toBe('Sun, 15 Mar 2026 14:00:00 GMT');
    });

    it('reports a 304 as not modified and parses nothing', function (): void {
        fakeFeed('feed.xml', 304);

        $response = $this->client->fetch(CALM_CHANNEL_ID, '"abc123"');

        expect($response->notModified)->toBeTrue()
            ->and($response->entries)->toBeEmpty();
    });
});

describe('failures', function (): void {
    it('throws when the feed cannot be reached', function (): void {
        fakeFeedFailure();

        $this->client->fetch(CALM_CHANNEL_ID);
    })->throws(FeedUnavailableException::class);

    it('throws when the channel feed no longer exists', function (): void {
        fakeFeed('feed.xml', 404);

        $this->client->fetch(CALM_CHANNEL_ID);
    })->throws(FeedUnavailableException::class);

    it('throws rather than fatals when YouTube returns an HTML error page', function (): void {
        fakeFeed('feed-malformed.xml');

        $this->client->fetch(CALM_CHANNEL_ID);
    })->throws(FeedUnavailableException::class);

    it('retries a server error before giving up', function (): void {
        Http::fake([
            'www.youtube.com/feeds/videos.xml*' => Http::sequence()
                ->push('', 503)
                ->push(youtubeFixture('feed-single-entry.xml'), 200),
        ]);

        $response = $this->client->fetch(CALM_CHANNEL_ID);

        expect($response->entries)->toHaveCount(1);
        Http::assertSentCount(2);
    });
});
