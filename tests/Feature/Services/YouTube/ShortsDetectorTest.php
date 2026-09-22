<?php

use App\Models\Channel;
use App\Models\Video;
use App\Services\YouTube\ShortsDetector;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->detector = app(ShortsDetector::class);
});

function videoLasting(?int $seconds, ?bool $isShort = null): Video
{
    return Video::factory()->for(Channel::factory())->create([
        'youtube_video_id' => CALM_VIDEO_ID,
        'duration_seconds' => $seconds,
        'is_short' => $isShort,
    ]);
}

it('rules out anything longer than the probe ceiling without making a request', function (int $seconds): void {
    $result = $this->detector->isShort(videoLasting($seconds));

    expect($result)->toBeFalse();
    Http::assertNothingSent();
})->with([
    'a normal video' => [724],
    'an hour long' => [3723],
    'one second over the ceiling' => [181],
]);

it('probes the shorts url for a video short enough to be a Short', function (): void {
    fakeShortsProbe();

    $this->detector->isShort(videoLasting(95));

    Http::assertSent(fn (Request $request): bool => $request->url() ===
        'https://www.youtube.com/shorts/'.CALM_VIDEO_ID);
});

it('does not follow the redirect when probing', function (): void {
    fakeShortsProbe();

    $this->detector->isShort(videoLasting(95));

    Http::assertSent(fn (Request $request): bool => $request->method() === 'HEAD');
});

it('reads the probe status to decide', function (int $status, bool $expected): void {
    fakeShortsProbe($status);

    expect($this->detector->isShort(videoLasting(95)))->toBe($expected);
})->with([
    'a Short answers 200' => [200, true],
    'a normal video redirects' => [303, false],
    'a permanent redirect is also a normal video' => [302, false],
]);

it('probes a video at exactly the ceiling', function (): void {
    fakeShortsProbe(200);

    expect($this->detector->isShort(videoLasting(180)))->toBeTrue();
    Http::assertSentCount(1);
});

describe('when the probe cannot answer', function (): void {
    it('falls back to the duration rule', function (int $seconds, bool $expected): void {
        fakeShortsProbeFailure();

        expect($this->detector->isShort(videoLasting($seconds)))->toBe($expected);
    })->with([
        'under a minute is treated as a Short' => [42, true],
        'exactly a minute is treated as a Short' => [60, true],
        'over a minute is kept' => [95, false],
    ]);

    it('returns null when there is no duration to fall back to', function (): void {
        fakeShortsProbeFailure();

        expect($this->detector->isShort(videoLasting(null)))->toBeNull();
    });
});

it('probes a video with no duration, because nothing rules it out', function (): void {
    fakeShortsProbe(200);

    expect($this->detector->isShort(videoLasting(null)))->toBeTrue();
});

it('never re-checks a video that has already been decided', function (bool $stored): void {
    $video = videoLasting(95, $stored);

    expect($this->detector->isShort($video))->toBe($stored);
    Http::assertNothingSent();
})->with([
    'known to be a Short' => [true],
    'known not to be a Short' => [false],
]);

it('uses the duration rule alone when probing is turned off', function (int $seconds, bool $expected): void {
    config()->set('calm-tube.shorts.probe', false);

    expect($this->detector->isShort(videoLasting($seconds)))->toBe($expected);
    Http::assertNothingSent();
})->with([
    'under a minute' => [42, true],
    'over a minute' => [95, false],
]);

it('respects a configured ceiling', function (): void {
    config()->set('calm-tube.shorts.probe_max_seconds', 60);

    expect($this->detector->isShort(videoLasting(95)))->toBeFalse();
    Http::assertNothingSent();
});
