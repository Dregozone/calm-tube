<?php

use App\Enums\LiveStatus;
use App\Exceptions\ApiRequestException;
use App\Exceptions\QuotaExceededException;
use App\Services\YouTube\DataApiClient;
use App\Support\ChannelData;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->client = app(DataApiClient::class);
});

describe('configuration', function (): void {
    it('reports that it is configured when an API key is set', function (): void {
        config()->set('calm-tube.api_key', 'test-api-key');

        expect($this->client->isConfigured())->toBeTrue();
    });

    it('reports that it is not configured when the API key is missing', function (?string $key): void {
        config()->set('calm-tube.api_key', $key);

        expect($this->client->isConfigured())->toBeFalse();
    })->with([
        'null' => [null],
        'empty string' => [''],
    ]);

    it('sends the configured API key with every request', function (): void {
        fakeVideosList('videos.list-single.json');

        $this->client->videos([CALM_VIDEO_ID]);

        Http::assertSent(fn (Request $request): bool => $request['key'] === 'test-api-key');
    });
});

describe('videos', function (): void {
    it('returns the duration in seconds keyed by video id', function (): void {
        fakeVideosList('videos.list-single.json');

        $videos = $this->client->videos([CALM_VIDEO_ID]);

        expect($videos)->toHaveKey(CALM_VIDEO_ID)
            ->and($videos[CALM_VIDEO_ID]->durationSeconds)->toBe(377);
    });

    it('sends one request per 50 ids', function (int $count, int $expectedRequests): void {
        fakeVideosList('videos.list-empty.json');

        $this->client->videos(array_map(fn (int $n): string => sprintf('calmvid%04d', $n), range(1, $count)));

        Http::assertSentCount($expectedRequests);
    })->with([
        'a single id' => [1, 1],
        'exactly one batch' => [50, 1],
        'one over a batch' => [51, 2],
        'several batches' => [120, 3],
    ]);

    it('never asks for the snippet, so an updated title cannot reach the archive', function (): void {
        fakeVideosList('videos.list-single.json');

        $this->client->videos([CALM_VIDEO_ID]);

        Http::assertSent(fn (Request $request): bool => ! str_contains($request['part'], 'snippet'));
    });

    it('requests the parts it needs to determine duration, live status and availability', function (): void {
        fakeVideosList('videos.list-single.json');

        $this->client->videos([CALM_VIDEO_ID]);

        Http::assertSent(function (Request $request): bool {
            $parts = explode(',', $request['part']);

            return in_array('contentDetails', $parts, true)
                && in_array('liveStreamingDetails', $parts, true)
                && in_array('status', $parts, true);
        });
    });

    it('omits ids that YouTube did not return', function (): void {
        fakeVideosList('videos.list-empty.json');

        $videos = $this->client->videos([CALM_VIDEO_ID, 'calmvid0002']);

        expect($videos)->toBeEmpty();
    });

    it('sends no request when given no ids', function (): void {
        $videos = $this->client->videos([]);

        expect($videos)->toBeEmpty();
        Http::assertNothingSent();
    });

    it('derives the live status from the streaming details', function (string $videoId, LiveStatus $expected): void {
        fakeVideosList('videos.list-live.json');

        $videos = $this->client->videos([$videoId]);

        expect($videos[$videoId]->liveStatus)->toBe($expected);
    })->with(fn (): array => [
        'started but not ended' => ['calmlive001', LiveStatus::Live],
        'scheduled but not started' => ['calmupcm001', LiveStatus::Upcoming],
        'started and ended' => ['calmdone001', LiveStatus::None],
        'no streaming details at all' => ['calmplain01', LiveStatus::None],
    ]);

    it('returns the scheduled start time for an upcoming premiere', function (): void {
        fakeVideosList('videos.list-live.json');

        $videos = $this->client->videos(['calmupcm001']);

        expect($videos['calmupcm001']->scheduledStartAt->toIso8601String())
            ->toBe('2026-03-20T19:30:00+00:00');
    });

    it('returns no duration for a stream that is still live', function (): void {
        fakeVideosList('videos.list-live.json');

        $videos = $this->client->videos(['calmlive001']);

        expect($videos['calmlive001']->durationSeconds)->toBeNull();
    });

    it('returns the duration of a stream that has finished', function (): void {
        fakeVideosList('videos.list-live.json');

        $videos = $this->client->videos(['calmdone001']);

        expect($videos['calmdone001']->durationSeconds)->toBe(4442);
    });
});

describe('channels', function (): void {
    it('returns the channel metadata for a channel id', function (): void {
        fakeChannelsList();

        $channel = $this->client->channelById(CALM_CHANNEL_ID);

        expect($channel)->toBeInstanceOf(ChannelData::class)
            ->and($channel->channelId)->toBe(CALM_CHANNEL_ID)
            ->and($channel->title)->toBe(CALM_CHANNEL_TITLE)
            ->and($channel->handle)->toBe('@practicalengineering')
            ->and($channel->uploadsPlaylistId)->toBe('UUMOqf8ab-42UUQIdVoKwjlQ')
            ->and($channel->avatarUrl)->toBeUrl();
    });

    it('looks a channel up by id, handle or legacy username', function (string $method, string $argument, string $parameter, string $expected): void {
        fakeChannelsList();

        $this->client->{$method}($argument);

        Http::assertSent(fn (Request $request): bool => $request[$parameter] === $expected);
    })->with([
        'by id' => ['channelById', CALM_CHANNEL_ID, 'id', CALM_CHANNEL_ID],
        'by handle' => ['channelByHandle', '@practicalengineering', 'forHandle', '@practicalengineering'],
        'by legacy username' => ['channelByUsername', 'PracticalEng', 'forUsername', 'PracticalEng'],
    ]);

    it('sends the handle with a leading @ even when given without one', function (): void {
        fakeChannelsList();

        $this->client->channelByHandle('practicalengineering');

        Http::assertSent(fn (Request $request): bool => $request['forHandle'] === '@practicalengineering');
    });

    it('returns null when YouTube returns no channel', function (): void {
        fakeChannelsList('empty-items.json');

        expect($this->client->channelByHandle('@nobodyhere'))->toBeNull();
    });

    it('returns the channel id that owns a video', function (): void {
        fakeVideosList('videos.list-channel-lookup.json');

        expect($this->client->channelIdForVideo(CALM_VIDEO_ID))->toBe(CALM_CHANNEL_ID);
    });

    it('returns null when the video used for lookup does not exist', function (): void {
        fakeVideosList('videos.list-empty.json');

        expect($this->client->channelIdForVideo('calmvid9999'))->toBeNull();
    });
});

describe('errors', function (): void {
    it('throws a quota exception when the daily allowance is exhausted', function (): void {
        fakeVideosList('quota-exceeded.json', 403);

        $this->client->videos([CALM_VIDEO_ID]);
    })->throws(QuotaExceededException::class);

    it('throws a request exception when the API key is rejected', function (): void {
        fakeChannelsList('key-invalid.json', 400);

        $this->client->channelById(CALM_CHANNEL_ID);
    })->throws(ApiRequestException::class);

    it('retries a server error before giving up', function (): void {
        Http::fake([
            'www.googleapis.com/youtube/v3/videos*' => Http::sequence()
                ->push('', 503)
                ->push(youtubeFixture('videos.list-single.json'), 200),
        ]);

        $videos = $this->client->videos([CALM_VIDEO_ID]);

        expect($videos)->toHaveKey(CALM_VIDEO_ID);
        Http::assertSentCount(2);
    });
});
