<?php

use App\Models\Video;

it('is not resumable before it has been started', function (): void {
    expect(Video::factory()->create(['resume_seconds' => null])->isResumable())->toBeFalse();
});

it('is not resumable a few seconds in, where starting again costs nothing', function (): void {
    expect(Video::factory()->create(['resume_seconds' => 8])->isResumable())->toBeFalse();
});

it('is resumable once you are properly into it', function (): void {
    $video = Video::factory()->create(['resume_seconds' => 600, 'duration_seconds' => 2400]);

    expect($video->isResumable())->toBeTrue();
});

it('is not resumable within a breath of the end', function (): void {
    $video = Video::factory()->create(['resume_seconds' => 2395, 'duration_seconds' => 2400]);

    expect($video->isResumable())->toBeFalse();
});

it('is resumable when the duration is unknown, since nothing says otherwise', function (): void {
    $video = Video::factory()->create(['resume_seconds' => 600, 'duration_seconds' => null]);

    expect($video->isResumable())->toBeTrue();
});

it('reads the position back as a timestamp', function (): void {
    expect(Video::factory()->create(['resume_seconds' => 1122])->resume_for_humans)->toBe('18:42');
});

describe('the progress bar', function (): void {
    it('has nothing to show without both numbers', function (): void {
        expect(Video::factory()->create(['resume_seconds' => null, 'duration_seconds' => 100])->percent_watched)
            ->toBeNull()
            ->and(Video::factory()->create(['resume_seconds' => 50, 'duration_seconds' => null])->percent_watched)
            ->toBeNull();
    });

    it('is the fraction of the way through', function (): void {
        $video = Video::factory()->create(['resume_seconds' => 600, 'duration_seconds' => 2400]);

        expect($video->percent_watched)->toBe(25);
    });

    it('never exceeds its track', function (): void {
        // The player reports a fraction past YouTube's own duration often
        // enough to matter, and a bar wider than its track looks broken.
        $video = Video::factory()->create(['resume_seconds' => 2405, 'duration_seconds' => 2400]);

        expect($video->percent_watched)->toBe(100);
    });

    it('survives a zero duration rather than dividing by it', function (): void {
        $video = Video::factory()->create(['resume_seconds' => 10, 'duration_seconds' => 0]);

        expect($video->percent_watched)->toBeNull();
    });
});
