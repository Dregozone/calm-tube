<?php

use App\Models\ReadingListCourse;

it('formats a duration in minutes as hours and minutes', function (?int $minutes, ?string $expected): void {
    expect(ReadingListCourse::formatMinutes($minutes))->toBe($expected);
})->with([
    'hours and minutes' => [390, '6 h 30 m'],
    'whole hours' => [120, '2 h'],
    'under an hour' => [45, '45 m'],
    'a minute past the hour' => [61, '1 h 1 m'],
    'nothing' => [0, null],
    'unknown' => [null, null],
]);
