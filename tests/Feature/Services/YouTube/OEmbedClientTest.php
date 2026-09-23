<?php

use App\Exceptions\VideoLookupException;
use App\Services\YouTube\OEmbedClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('calm-tube.refresh.retry_delay', 0);
});

it('describes a video without an API key', function (): void {
    config()->set('calm-tube.api_key');
    fakeOEmbed();

    $embed = app(OEmbedClient::class)->lookup(CALM_VIDEO_ID);

    expect($embed->videoId)->toBe(CALM_VIDEO_ID)
        ->and($embed->title)->toBe('2 Hours of Calm Jazz for Deep Work')
        ->and($embed->authorName)->toBe('Quiet Rooms')
        ->and($embed->thumbnailUrl)->toBe('https://i.ytimg.com/vi/'.CALM_VIDEO_ID.'/hqdefault.jpg');
});

it('asks about the video by its watch link', function (): void {
    fakeOEmbed();

    app(OEmbedClient::class)->lookup(CALM_VIDEO_ID);

    Http::assertSent(fn ($request): bool => str_contains(
        urldecode($request->url()),
        'url=https://www.youtube.com/watch?v='.CALM_VIDEO_ID,
    ));
});

it('refuses a video that cannot be played here', function (int $status): void {
    fakeOEmbed($status);

    app(OEmbedClient::class)->lookup(CALM_VIDEO_ID);
})->with([
    'private or not embeddable' => 401,
    'gone' => 404,
    'not a video id' => 400,
])->throws(VideoLookupException::class, 'can be played here');

it('says so when YouTube cannot be reached', function (): void {
    Http::fake(['www.youtube.com/oembed*' => fn () => throw new ConnectionException('timed out')]);

    app(OEmbedClient::class)->lookup(CALM_VIDEO_ID);
})->throws(VideoLookupException::class, "Couldn't reach YouTube");
