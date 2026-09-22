<?php

use App\Enums\RefreshStatus;
use App\Enums\RefreshTrigger;
use App\Models\Channel;
use App\Models\RefreshRun;
use App\Models\Video;
use App\Services\ChannelRefresher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->refresher = app(ChannelRefresher::class);
});

describe('ingest', function (): void {
    it('creates a video for every entry in the feed', function (): void {
        Storage::fake('local');
        fakeSuccessfulRefresh();
        $channel = calmChannel();

        $result = $this->refresher->refresh($channel);

        expect($result->newVideos)->toBe(15)
            ->and($channel->videos()->count())->toBe(15);
    });

    it('stores the fields it archives from the feed', function (): void {
        Storage::fake('local');
        fakeSuccessfulRefresh('feed-single-entry.xml', 'videos.list-single.json');
        $channel = calmChannel();

        $this->refresher->refresh($channel);

        $video = Video::where('youtube_video_id', CALM_VIDEO_ID)->sole();

        expect($video->title)->toBe('Calm Tube Test Video 01')
            ->and($video->description)->toContain('Description for Calm Tube Test Video 01.')
            ->and($video->published_at->toIso8601String())->toBe('2026-03-15T14:00:00+00:00')
            ->and($video->thumbnail_url)->toBe('https://i4.ytimg.com/vi/calmvid0001/hqdefault.jpg')
            ->and($video->channel_id)->toBe($channel->id);
    });

    it('creates nothing on a second refresh of an unchanged feed', function (): void {
        Storage::fake('local');
        fakeSuccessfulRefresh();
        $channel = calmChannel();

        $this->refresher->refresh($channel);
        $result = $this->refresher->refresh($channel->fresh());

        expect($result->newVideos)->toBe(0)
            ->and(Video::count())->toBe(15);
    });

    it('adds only the entries it has not seen before', function (): void {
        Storage::fake('local');
        fakeFeedSequence('feed-single-entry.xml', 'feed.xml');
        fakeVideosList();
        fakeThumbnailDownloads();
        $channel = calmChannel();

        $this->refresher->refresh($channel);
        $result = $this->refresher->refresh($channel->fresh());

        expect($result->newVideos)->toBe(14)
            ->and(Video::count())->toBe(15);
    });
});

describe('the archive guarantee', function (): void {
    it('does not update the title when the uploader retitles the video', function (): void {
        Storage::fake('local');
        fakeFeedSequence('feed-single-entry.xml', 'feed-retitled.xml');
        fakeVideosList('videos.list-single.json');
        fakeThumbnailDownloads();
        $channel = calmChannel();

        $this->refresher->refresh($channel);
        $this->refresher->refresh($channel->fresh());

        expect(Video::where('youtube_video_id', CALM_VIDEO_ID)->sole()->title)
            ->toBe('Calm Tube Test Video 01');
    });

    it('does not update the thumbnail when the uploader swaps it', function (): void {
        Storage::fake('local');
        fakeFeedSequence('feed-single-entry.xml', 'feed-retitled.xml');
        fakeVideosList('videos.list-single.json');
        fakeThumbnailDownloads();
        $channel = calmChannel();

        $this->refresher->refresh($channel);
        $archivedPath = Video::sole()->thumbnail_path;
        $this->refresher->refresh($channel->fresh());

        $video = Video::where('youtube_video_id', CALM_VIDEO_ID)->sole();

        expect($video->thumbnail_url)->toBe('https://i4.ytimg.com/vi/calmvid0001/hqdefault.jpg')
            ->and($video->thumbnail_path)->toBe($archivedPath);
    });

    it('does not re-download a thumbnail it has already archived', function (): void {
        Storage::fake('local');
        fakeFeedSequence('feed-single-entry.xml', 'feed-retitled.xml');
        fakeVideosList('videos.list-single.json');
        fakeThumbnailDownloads();
        $channel = calmChannel();

        $this->refresher->refresh($channel);
        $downloadsAfterFirst = count(Http::recorded());
        $this->refresher->refresh($channel->fresh());

        expect(count(Http::recorded()))->toBe($downloadsAfterFirst + 1);
    });
});

describe('conditional requests', function (): void {
    it('stores the feed validators on the channel', function (): void {
        Storage::fake('local');
        fakeFeed('feed-empty.xml', 200, ['ETag' => '"abc123"', 'Last-Modified' => 'Sun, 15 Mar 2026 14:00:00 GMT']);
        $channel = calmChannel();

        $this->refresher->refresh($channel);

        expect($channel->fresh()->feed_etag)->toBe('"abc123"')
            ->and($channel->fresh()->feed_last_modified)->toBe('Sun, 15 Mar 2026 14:00:00 GMT');
    });

    it('does nothing further when the feed reports no change', function (): void {
        fakeFeed('feed.xml', 304);
        $channel = calmChannel(['feed_etag' => '"abc123"']);

        $result = $this->refresher->refresh($channel);

        expect($result->newVideos)->toBe(0)
            ->and($result->status)->toBe(RefreshStatus::Ok)
            ->and(Video::count())->toBe(0);

        Http::assertSentCount(1);
    });
});

describe('enrichment', function (): void {
    it('stores the duration returned by the API', function (): void {
        Storage::fake('local');
        fakeSuccessfulRefresh('feed-single-entry.xml', 'videos.list-single.json');

        $this->refresher->refresh(calmChannel());

        expect(Video::sole()->duration_seconds)->toBe(377);
    });

    it('marks a video unavailable when the API does not return it', function (): void {
        Storage::fake('local');
        fakeFeed('feed-single-entry.xml');
        fakeVideosList('videos.list-empty.json');
        fakeThumbnailDownloads();

        $this->refresher->refresh(calmChannel());

        expect(Video::sole()->unavailable_at)->not->toBeNull()
            ->and(Video::inFeed()->count())->toBe(0);
    });

    it('records when a video was enriched', function (): void {
        Storage::fake('local');
        $this->freezeTime();
        fakeSuccessfulRefresh('feed-single-entry.xml', 'videos.list-single.json');

        $this->refresher->refresh(calmChannel());

        expect(Video::sole()->enriched_at->timestamp)->toBe(now()->timestamp);
    });
});

describe('Shorts', function (): void {
    it('stores a Short but keeps it out of the feed', function (): void {
        Storage::fake('local');
        fakeFeed('feed-single-entry.xml');
        fakeVideosList('videos.list-short.json');
        fakeShortsProbe(200);

        $this->refresher->refresh(calmChannel());

        expect(Video::sole()->is_short)->toBeTrue()
            ->and(Video::inFeed()->count())->toBe(0);
    });

    it('never downloads a thumbnail for a Short', function (): void {
        Storage::fake('local');
        fakeFeed('feed-single-entry.xml');
        fakeVideosList('videos.list-short.json');
        fakeShortsProbe(200);

        $this->refresher->refresh(calmChannel());

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'ytimg.com'));
    });

    it('does not probe videos that are too long to be a Short', function (): void {
        Storage::fake('local');
        fakeSuccessfulRefresh();

        $this->refresher->refresh(calmChannel());

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/shorts/'));
    });
});

describe('degrading without the API', function (): void {
    it('still ingests videos when no API key is configured', function (): void {
        Storage::fake('local');
        config()->set('calm-tube.api_key');
        fakeFeed('feed-single-entry.xml');
        fakeShortsProbe();
        fakeThumbnailDownloads();

        $result = $this->refresher->refresh(calmChannel());

        expect($result->newVideos)->toBe(1)
            ->and($result->status)->toBe(RefreshStatus::Degraded)
            ->and(Video::sole()->duration_seconds)->toBeNull()
            ->and(Video::sole()->enriched_at)->toBeNull()
            ->and(Video::inFeed()->count())->toBe(1);
    });

    it('sends no API request when no key is configured', function (): void {
        Storage::fake('local');
        config()->set('calm-tube.api_key');
        fakeFeed('feed-single-entry.xml');
        fakeShortsProbe();
        fakeThumbnailDownloads();

        $this->refresher->refresh(calmChannel());

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'googleapis.com'));
    });

    it('keeps the videos it ingested when the API quota is exhausted', function (): void {
        Storage::fake('local');
        fakeFeed('feed-single-entry.xml');
        fakeVideosList('quota-exceeded.json', 403);
        fakeShortsProbe();
        fakeThumbnailDownloads();

        $result = $this->refresher->refresh(calmChannel());

        expect($result->newVideos)->toBe(1)
            ->and($result->status)->toBe(RefreshStatus::Degraded)
            ->and(Video::sole()->duration_seconds)->toBeNull()
            ->and(Video::inFeed()->count())->toBe(1);
    });
});

describe('failures', function (): void {
    it('reports a failure and stores the error when the feed cannot be reached', function (): void {
        $channel = calmChannel(['last_refresh_error' => null]);
        fakeFeedFailure();

        $result = $this->refresher->refresh($channel);

        expect($result->status)->toBe(RefreshStatus::Failed)
            ->and($result->errorMessage)->not->toBeEmpty()
            ->and(Video::count())->toBe(0)
            ->and($channel->fresh()->last_refresh_error)->not->toBeNull();
    });

    it('leaves the last successful refresh timestamp alone when a refresh fails', function (): void {
        $channel = calmChannel(['last_refreshed_at' => '2026-03-01 09:00:00']);
        fakeFeedFailure();

        $this->refresher->refresh($channel);

        expect($channel->fresh()->last_refreshed_at->toDateTimeString())->toBe('2026-03-01 09:00:00');
    });

    it('clears a previous error after a successful refresh', function (): void {
        Storage::fake('local');
        $channel = Channel::factory()->failing()->create(['youtube_channel_id' => CALM_CHANNEL_ID]);
        fakeSuccessfulRefresh('feed-single-entry.xml', 'videos.list-single.json');

        $this->refresher->refresh($channel);

        expect($channel->fresh()->last_refresh_error)->toBeNull()
            ->and($channel->fresh()->last_refreshed_at)->not->toBeNull();
    });
});

describe('refresh runs', function (): void {
    it('records the outcome of a successful refresh', function (): void {
        Storage::fake('local');
        fakeSuccessfulRefresh('feed-single-entry.xml', 'videos.list-single.json');
        $channel = calmChannel();

        $this->refresher->refresh($channel, RefreshTrigger::Scheduled);

        $run = RefreshRun::sole();

        expect($run->channel_id)->toBe($channel->id)
            ->and($run->status)->toBe(RefreshStatus::Ok)
            ->and($run->trigger)->toBe(RefreshTrigger::Scheduled)
            ->and($run->new_videos_count)->toBe(1)
            ->and($run->finished_at)->not->toBeNull();
    });

    it('records the outcome of a failed refresh', function (): void {
        fakeFeedFailure();

        $this->refresher->refresh(calmChannel());

        $run = RefreshRun::sole();

        expect($run->status)->toBe(RefreshStatus::Failed)
            ->and($run->new_videos_count)->toBe(0)
            ->and($run->error_message)->not->toBeEmpty()
            ->and($run->finished_at)->not->toBeNull();
    });

    it('defaults to a manual trigger', function (): void {
        fakeFeed('feed-empty.xml');

        $this->refresher->refresh(calmChannel());

        expect(RefreshRun::sole()->trigger)->toBe(RefreshTrigger::Manual);
    });
});
