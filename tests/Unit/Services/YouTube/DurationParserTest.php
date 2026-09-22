<?php

use App\Services\YouTube\DurationParser;

beforeEach(function (): void {
    $this->parser = new DurationParser;
});

it('converts an ISO 8601 duration to seconds', function (?string $iso, ?int $expected): void {
    expect($this->parser->toSeconds($iso))->toBe($expected);
})->with([
    'seconds only' => ['PT45S', 45],
    'minutes and seconds' => ['PT12M4S', 724],
    'minutes only' => ['PT8M', 480],
    'hours, minutes and seconds' => ['PT1H2M3S', 3723],
    'whole hour' => ['PT1H', 3600],
    'hours and seconds, no minutes' => ['PT2H30S', 7230],
    'multi-day stream' => ['P1DT2H', 93600],
    'exactly the Shorts ceiling' => ['PT3M', 180],
]);

it('returns null for a duration it cannot use', function (?string $iso): void {
    expect($this->parser->toSeconds($iso))->toBeNull();
})->with([
    'live stream' => ['P0D'],
    'zero seconds' => ['PT0S'],
    'null' => [null],
    'empty string' => [''],
    'not a duration' => ['12:04'],
    'malformed' => ['PTXM'],
]);

it('formats a duration under an hour as minutes and seconds', function (int $seconds, string $expected): void {
    expect($this->parser->toHuman($seconds))->toBe($expected);
})->with([
    'under a minute pads the seconds' => [45, '0:45'],
    'single digit minutes' => [724, '12:04'],
    'exactly a minute' => [60, '1:00'],
    'just under an hour' => [3599, '59:59'],
]);

it('formats a duration of an hour or more as hours, minutes and seconds', function (int $seconds, string $expected): void {
    expect($this->parser->toHuman($seconds))->toBe($expected);
})->with([
    'exactly an hour' => [3600, '1:00:00'],
    'pads minutes and seconds' => [3723, '1:02:03'],
    'multi-day stream' => [93600, '26:00:00'],
]);

it('returns null when formatting an unknown duration', function (): void {
    expect($this->parser->toHuman(null))->toBeNull();
});
