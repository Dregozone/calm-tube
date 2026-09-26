<?php

use App\Enums\RefreshStatus;
use App\Enums\RefreshTrigger;
use App\Models\Channel;
use App\Models\RefreshRun;
use App\Models\Video;
use App\Services\ChannelRefresher;
use App\Support\RefreshResult;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->refresher = app(ChannelRefresher::class);
});

describe('ingest', function (): void {
    it('creates a video for every entry in the feed', function (): void {
        Storage::fake('images');
        fakeSuccessfulRefresh();
        $channel = calmChannel();

        $result = $this->refresher->refresh($channel);

        expect($result->newVideos)->toBe(15)
            ->and($channel->videos()->count())->toBe(15);
    });

    it('stores the fields it archives from the feed', function (): void {
        Storage::fake('images');
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
        Storage::fake('images');
        fakeSuccessfulRefresh();
        $channel = calmChannel();

        $this->refresher->refresh($channel);
        $result = $this->refresher->refresh($channel->fresh());

        expect($result->newVideos)->toBe(0)
            ->and(Video::count())->toBe(15);
    });

    it('adds only the entries it has not seen before', function (): void {
        Storage::fake('images');
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
        Storage::fake('images');
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
        Storage::fake('images');
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
        Storage::fake('images');
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
        Storage::fake('images');
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
        Storage::fake('images');
        fakeSuccessfulRefresh('feed-single-entry.xml', 'videos.list-single.json');

        $this->refresher->refresh(calmChannel());

        expect(Video::sole()->duration_seconds)->toBe(377);
    });

    it('marks a video unavailable when the API does not return it', function (): void {
        Storage::fake('images');
        fakeFeed('feed-single-entry.xml');
        fakeVideosList('videos.list-empty.json');
        fakeThumbnailDownloads();

        $this->refresher->refresh(calmChannel());

        expect(Video::sole()->unavailable_at)->not->toBeNull()
            ->and(Video::inFeed()->count())->toBe(0);
    });

    it('records when a video was enriched', function (): void {
        Storage::fake('images');
        $this->freezeTime();
        fakeSuccessfulRefresh('feed-single-entry.xml', 'videos.list-single.json');

        $this->refresher->refresh(calmChannel());

        expect(Video::sole()->enriched_at->timestamp)->toBe(now()->timestamp);
    });
});

describe('Shorts', function (): void {
    it('stores a Short but keeps it out of the feed', function (): void {
        Storage::fake('images');
        fakeFeed('feed-single-entry.xml');
        fakeVideosList('videos.list-short.json');
        fakeShortsProbe(200);

        $this->refresher->refresh(calmChannel());

        expect(Video::sole()->is_short)->toBeTrue()
            ->and(Video::inFeed()->count())->toBe(0);
    });

    it('never downloads a thumbnail for a Short', function (): void {
        Storage::fake('images');
        fakeFeed('feed-single-entry.xml');
        fakeVideosList('videos.list-short.json');
        fakeShortsProbe(200);

        $this->refresher->refresh(calmChannel());

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'ytimg.com'));
    });

    it('does not probe videos that are too long to be a Short', function (): void {
        Storage::fake('images');
        fakeSuccessfulRefresh();

        $this->refresher->refresh(calmChannel());

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/shorts/'));
    });
});

describe('degrading without the API', function (): void {
    it('still ingests videos when no API key is configured', function (): void {
        Storage::fake('images');
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
        Storage::fake('images');
        config()->set('calm-tube.api_key');
        fakeFeed('feed-single-entry.xml');
        fakeShortsProbe();
        fakeThumbnailDownloads();

        $this->refresher->refresh(calmChannel());

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'googleapis.com'));
    });

    it('keeps the videos it ingested when the API quota is exhausted', function (): void {
        Storage::fake('images');
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
    /*
     * A feed failure alone no longer fails a refresh: it falls back to the
     * uploads playlist. These cover the case where there is no API key, and
     * so nowhere else to look.
     */

    beforeEach(function (): void {
        config()->set('calm-tube.api_key');
    });

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
        Storage::fake('images');
        config()->set('calm-tube.api_key', 'test-api-key');
        $channel = Channel::factory()->failing()->create(['youtube_channel_id' => CALM_CHANNEL_ID]);
        fakeSuccessfulRefresh('feed-single-entry.xml', 'videos.list-single.json');

        $this->refresher->refresh($channel);

        expect($channel->fresh()->last_refresh_error)->toBeNull()
            ->and($channel->fresh()->last_refreshed_at)->not->toBeNull();
    });
});

describe('refresh runs', function (): void {
    it('records the outcome of a successful refresh', function (): void {
        Storage::fake('images');
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
        // No key, so the feed failure has nowhere to fall back to.
        config()->set('calm-tube.api_key');
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

describe('recovering an overflowed feed', function (): void {
    it('walks the uploads playlist when every feed entry is new', function (): void {
        Storage::fake('images');
        $channel = calmChannel(['uploads_playlist_id' => 'UUMOqf8ab-42UUQIdVoKwjlQ']);

        // A previous refresh left the channel with a video the feed no longer holds.
        Video::factory()->for($channel)->create(['youtube_video_id' => 'bkfilvid004']);

        fakeFeed('feed.xml');
        fakeVideosList();
        fakeUploadsPlaylist();
        fakeThumbnailDownloads();

        $result = $this->refresher->refresh($channel);

        // 15 from the feed, plus the three the window had pushed out.
        expect($result->newVideos)->toBe(18)
            ->and(requestsTo('playlistItems'))->toBe(1);
    });

    it('does not walk the playlist on a channel it has never refreshed', function (): void {
        Storage::fake('images');
        fakeSuccessfulRefresh();

        $result = $this->refresher->refresh(
            calmChannel(['uploads_playlist_id' => 'UUMOqf8ab-42UUQIdVoKwjlQ'])
        );

        expect($result->newVideos)->toBe(15)
            ->and(requestsTo('playlistItems'))->toBe(0);
    });

    it('does not walk the playlist when the feed held something it already had', function (): void {
        Storage::fake('images');
        $channel = calmChannel(['uploads_playlist_id' => 'UUMOqf8ab-42UUQIdVoKwjlQ']);
        fakeFeedSequence('feed-single-entry.xml', 'feed.xml');
        fakeVideosList();
        fakeThumbnailDownloads();

        $this->refresher->refresh($channel);
        $this->refresher->refresh($channel->fresh());

        expect(requestsTo('playlistItems'))->toBe(0);
    });

    it('does not walk the playlist without an API key', function (): void {
        Storage::fake('images');
        config()->set('calm-tube.api_key');
        $channel = calmChannel(['uploads_playlist_id' => 'UUMOqf8ab-42UUQIdVoKwjlQ']);
        Video::factory()->for($channel)->create(['youtube_video_id' => 'bkfilvid004']);

        fakeFeed('feed.xml');
        fakeShortsProbe();
        fakeThumbnailDownloads();

        $this->refresher->refresh($channel);

        expect(requestsTo('playlistItems'))->toBe(0);
    });
});

describe('when the feed is unavailable', function (): void {
    /*
     * YouTube's RSS endpoint has answered 404 intermittently since late 2025,
     * and did so for every channel at once. The uploads playlist lists the
     * same uploads, so an unreachable feed should cost a quota unit rather
     * than the refresh.
     */

    it('falls back to the uploads playlist when the feed 404s', function (): void {
        Storage::fake('images');
        fakeFeed('feed.xml', 404);
        fakeUploadsPlaylist('playlist-items-page2.json');
        fakeVideosList('videos.list-empty.json');
        fakeThumbnailDownloads();
        $channel = calmChannel(['uploads_playlist_id' => 'UUMOqf8ab-42UUQIdVoKwjlQ']);

        $result = $this->refresher->refresh($channel);

        expect($result->status)->toBe(RefreshStatus::Ok)
            ->and($result->newVideos)->toBe(30)
            ->and($channel->fresh()->last_refresh_error)->toBeNull()
            ->and($channel->fresh()->last_refreshed_at)->not->toBeNull();
    });

    it('falls back when the feed cannot be reached at all', function (): void {
        Storage::fake('images');
        fakeFeedFailure();
        fakeUploadsPlaylist('playlist-items-page2.json');
        fakeVideosList('videos.list-empty.json');
        fakeThumbnailDownloads();

        $result = $this->refresher->refresh(
            calmChannel(['uploads_playlist_id' => 'UUMOqf8ab-42UUQIdVoKwjlQ'])
        );

        expect($result->newVideos)->toBe(30);
    });

    it('stops at the first video it already has, so the fallback stays cheap', function (): void {
        Storage::fake('images');
        fakeFeed('feed.xml', 404);
        fakeUploadsPlaylist();
        fakeVideosList('videos.list-empty.json');
        fakeThumbnailDownloads();
        $channel = calmChannel(['uploads_playlist_id' => 'UUMOqf8ab-42UUQIdVoKwjlQ']);
        Video::factory()->for($channel)->create(['youtube_video_id' => 'bkfilvid003']);

        $result = $this->refresher->refresh($channel);

        expect($result->newVideos)->toBe(2)
            ->and(requestsTo('playlistItems'))->toBe(1);
    });

    it('still fails when there is no API key to fall back on', function (): void {
        config()->set('calm-tube.api_key');
        fakeFeed('feed.xml', 404);
        $channel = calmChannel();

        $result = $this->refresher->refresh($channel);

        expect($result->status)->toBe(RefreshStatus::Failed)
            ->and($channel->fresh()->last_refresh_error)->not->toBeNull();
    });

    it('skips the feed entirely when it has been turned off', function (): void {
        Storage::fake('images');
        config()->set('calm-tube.refresh.try_feed', false);
        fakeUploadsPlaylist('playlist-items-page2.json');
        fakeVideosList('videos.list-empty.json');
        fakeThumbnailDownloads();

        $result = $this->refresher->refresh(
            calmChannel(['uploads_playlist_id' => 'UUMOqf8ab-42UUQIdVoKwjlQ'])
        );

        expect($result->newVideos)->toBe(30)
            ->and(requestsTo('feeds/videos.xml'))->toBe(0);
    });

    it('does not fall back when the feed simply had nothing new', function (): void {
        fakeFeed('feed.xml', 304);

        $result = $this->refresher->refresh(
            calmChannel(['feed_etag' => '"abc123"', 'uploads_playlist_id' => 'UUMOqf8ab-42UUQIdVoKwjlQ'])
        );

        expect($result->newVideos)->toBe(0)
            ->and($result->status)->toBe(RefreshStatus::Ok)
            ->and(requestsTo('playlistItems'))->toBe(0);
    });
});

describe('sampling a noisy channel', function (): void {
    it('sets aside what the day it ingested holds past the limit', function (): void {
        Storage::fake('images');
        fakeSuccessfulRefresh('feed-single-entry.xml', 'videos.list-single.json');
        // A seeded video makes the all-new feed look like an overflow, so the
        // uploads walk that follows needs a stub of its own.
        fakeUploadsPlaylist('playlist-items-empty.json');
        $channel = calmChannel(['sample_limit' => 1]);

        // Already the longest thing published that day; the entry arriving
        // below is the offcut this is meant to hold back.
        $longest = Video::factory()->for($channel)->create([
            'duration_seconds' => 3 * 3600,
            'published_at' => '2026-03-15 09:00:00',
        ]);

        $this->refresher->refresh($channel);

        expect(Video::inFeed()->pluck('id')->all())->toBe([$longest->id])
            ->and(Video::where('youtube_video_id', CALM_VIDEO_ID)->sole()->isSetAside())->toBeTrue();
    });

    it('leaves a channel that publishes once a day untouched by a limit of three', function (): void {
        Storage::fake('images');
        fakeSuccessfulRefresh();
        $channel = calmChannel(['sample_limit' => 3]);

        // The rule is per day, so a steady channel never trips it.
        $this->refresher->refresh($channel);

        expect(Video::query()->setAside()->count())->toBe(0)
            ->and(Video::inFeed()->count())->toBe(15);
    });

    it('still counts everything it ingested as new', function (): void {
        Storage::fake('images');
        fakeSuccessfulRefresh('feed-single-entry.xml', 'videos.list-single.json');
        fakeUploadsPlaylist('playlist-items-empty.json');
        $channel = calmChannel(['sample_limit' => 1]);
        Video::factory()->for($channel)->create([
            'duration_seconds' => 3 * 3600,
            'published_at' => '2026-03-15 09:00:00',
        ]);

        // Sampling decides what you are shown, not what was fetched; the
        // refresh report should not start lying about the latter.
        expect($this->refresher->refresh($channel)->newVideos)->toBe(1);
    });

    it('leaves a channel without a limit completely alone', function (): void {
        Storage::fake('images');
        fakeSuccessfulRefresh();
        $channel = calmChannel();

        $this->refresher->refresh($channel);

        expect(Video::query()->setAside()->count())->toBe(0)
            ->and(Video::inFeed()->count())->toBe(15);
    });
});

describe('what a refresh reports', function (): void {
    it('counts only what you will actually see', function (): void {
        Storage::fake('images');
        fakeFeed('feed-single-entry.xml');
        fakeVideosList('videos.list-short.json');
        fakeShortsProbe(200);
        fakeThumbnailDownloads();
        $channel = calmChannel();

        $result = $this->refresher->refresh($channel);

        // Stored, so the archive grew; a Short, so the feed did not. Telling
        // someone "1 new video" and showing them nothing reads as a bug.
        expect($result->newVideos)->toBe(1)
            ->and($result->reachedFeed)->toBe(0)
            ->and($result->keptBack())->toBe(1);
    });

    it('says so in a sentence rather than leaving you to wonder', function (): void {
        Storage::fake('images');
        fakeFeed('feed-single-entry.xml');
        fakeVideosList('videos.list-short.json');
        fakeShortsProbe(200);
        fakeThumbnailDownloads();

        $result = $this->refresher->refresh(calmChannel());

        expect($result->summary())->toBe('No new videos. 1 other upload was a Short or held back.');
    });

    it('counts a video that did reach the feed', function (): void {
        Storage::fake('images');
        fakeSuccessfulRefresh('feed-single-entry.xml', 'videos.list-single.json');

        $result = $this->refresher->refresh(calmChannel());

        expect($result->newVideos)->toBe(1)
            ->and($result->reachedFeed)->toBe(1)
            ->and($result->summary())->toBe('1 new video.');
    });

    it('counts a video its channel sample limit held back', function (): void {
        Storage::fake('images');
        fakeSuccessfulRefresh('feed-single-entry.xml', 'videos.list-single.json');
        fakeUploadsPlaylist('playlist-items-empty.json');
        $channel = calmChannel(['sample_limit' => 1]);
        Video::factory()->for($channel)->create([
            'duration_seconds' => 3 * 3600,
            'published_at' => '2026-03-15 09:00:00',
        ]);

        $result = $this->refresher->refresh($channel);

        expect($result->newVideos)->toBe(1)
            ->and($result->reachedFeed)->toBe(0);
    });
});

describe('summarising a run across channels', function (): void {
    it('adds up what reached the feed and what did not', function (): void {
        $summary = RefreshResult::summarise([
            RefreshResult::ok(5, 1),
            RefreshResult::ok(3, 0),
        ]);

        expect($summary)->toBe('1 new video. 7 other uploads were Shorts or held back.');
    });

    it('says plainly when nothing arrived', function (): void {
        expect(RefreshResult::summarise([RefreshResult::ok(0, 0)]))
            ->toBe('No new videos.');
    });

    it('does not mention withheld uploads when there were none', function (): void {
        expect(RefreshResult::summarise([RefreshResult::ok(2, 2)]))
            ->toBe('2 new videos.');
    });

    it('counts the channels that failed', function (): void {
        $summary = RefreshResult::summarise([
            RefreshResult::ok(1, 1),
            RefreshResult::failed('Nope.'),
        ]);

        expect($summary)->toBe('1 new video. 1 channel failed to refresh.');
    });
});

describe('time limit', function (): void {
    afterEach(function (): void {
        set_time_limit(0);
    });

    it('gives each channel a fresh time budget in a web request', function (): void {
        Storage::fake('images');
        fakeSuccessfulRefresh();
        config()->set('calm-tube.refresh.seconds_per_channel', 120);
        set_time_limit(30);

        $this->refresher->refresh(calmChannel());

        expect(ini_get('max_execution_time'))->toBe('120');
    });

    it('leaves the command line without a time limit', function (): void {
        Storage::fake('images');
        fakeSuccessfulRefresh();
        set_time_limit(0);

        $this->refresher->refresh(calmChannel());

        expect(ini_get('max_execution_time'))->toBe('0');
    });
});
