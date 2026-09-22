<?php

use App\Models\Video;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(calmUser());
});

it('links to the watch page', function (): void {
    $video = Video::factory()->for(calmChannel())->create(['youtube_video_id' => CALM_VIDEO_ID]);

    Livewire::test('video-card', ['video' => $video])
        ->assertSee(route('videos.watch', $video), escape: false);
});

it('shows the archived title, channel and duration', function (): void {
    $video = Video::factory()->for(calmChannel())->create([
        'title' => 'How Arch Bridges Actually Work',
        'duration_seconds' => 724,
    ]);

    Livewire::test('video-card', ['video' => $video])
        ->assertSee('How Arch Bridges Actually Work')
        ->assertSee(CALM_CHANNEL_TITLE)
        ->assertSee('12:04');
});

it('serves the thumbnail from the archive rather than YouTube', function (): void {
    $video = Video::factory()->for(calmChannel())->archived()->create();

    Livewire::test('video-card', ['video' => $video])
        ->assertSee(route('thumbnails.show', $video), escape: false)
        ->assertDontSee('i.ytimg.com', escape: false);
});

it('shows a coarse relative date rather than an urgent one', function (): void {
    $this->travelTo('2026-03-15 12:00:00');
    $video = Video::factory()->for(calmChannel())->create(['published_at' => now()->subDays(3)]);

    Livewire::test('video-card', ['video' => $video])->assertSee('3 days ago');
});

it('shows no duration badge when the duration is unknown', function (): void {
    $video = Video::factory()->for(calmChannel())->unenriched()->create();

    Livewire::test('video-card', ['video' => $video])->assertOk();
});

it('shows no view count or rating', function (string $forbidden): void {
    $video = Video::factory()->for(calmChannel())->create();

    Livewire::test('video-card', ['video' => $video])->assertDontSee($forbidden);
})->with([
    'views' => ['views'],
    'likes' => ['likes'],
]);

describe('actions', function (): void {
    it('marks a video as watched', function (): void {
        $this->freezeTime();
        $video = Video::factory()->for(calmChannel())->create();

        Livewire::test('video-card', ['video' => $video])->call('toggleWatched');

        expect($video->fresh()->watched_at->timestamp)->toBe(now()->timestamp);
    });

    it('marks a watched video as unwatched', function (): void {
        $video = Video::factory()->for(calmChannel())->watched()->create();

        Livewire::test('video-card', ['video' => $video])->call('toggleWatched');

        expect($video->fresh()->watched_at)->toBeNull();
    });

    it('marks a watched video visibly', function (): void {
        $video = Video::factory()->for(calmChannel())->watched()->create();

        Livewire::test('video-card', ['video' => $video])->assertSee('watched');
    });

    it('hides a video from the feed without deleting it', function (): void {
        $this->freezeTime();
        $video = Video::factory()->for(calmChannel())->create();

        Livewire::test('video-card', ['video' => $video])->call('hide');

        expect($video->fresh()->hidden_at->timestamp)->toBe(now()->timestamp);
        $this->assertModelExists($video);
    });

    it('takes a hidden video out of the feed', function (): void {
        $video = Video::factory()->for(calmChannel())->create();

        Livewire::test('video-card', ['video' => $video])->call('hide');

        expect(Video::inFeed()->count())->toBe(0);
    });
});
