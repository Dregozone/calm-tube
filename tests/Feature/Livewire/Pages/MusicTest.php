<?php

use App\Models\Mix;
use App\Models\Video;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(calmUser());
    Storage::fake('local');
    config()->set('calm-tube.refresh.retry_delay', 0);
});

it('is in the sidebar', function (): void {
    $this->get(route('feed'))->assertSee(route('music'), escape: false);
});

describe('adding a mix', function (): void {
    it('adds a mix from a pasted link', function (string $link): void {
        fakeOEmbed();
        fakeVideosList('videos.list-single.json');
        fakeThumbnailDownloads();

        Livewire::test('pages::music')
            ->set('url', $link)
            ->call('addMix')
            ->assertHasNoErrors()
            ->assertSet('url', '')
            ->assertSee('2 Hours of Calm Jazz for Deep Work')
            ->assertSee('Quiet Rooms');

        expect(Mix::query()->sole())
            ->youtube_video_id->toBe(CALM_VIDEO_ID)
            ->title->toBe('2 Hours of Calm Jazz for Deep Work');
    })->with([
        'a watch link' => 'https://www.youtube.com/watch?v='.CALM_VIDEO_ID,
        'a watch link inside a playlist' => 'https://www.youtube.com/watch?v='.CALM_VIDEO_ID.'&list=PL123&index=2',
        'a short link' => 'https://youtu.be/'.CALM_VIDEO_ID,
        'a bare video id' => CALM_VIDEO_ID,
    ]);

    it('archives the thumbnail', function (): void {
        fakeOEmbed();
        fakeVideosList('videos.list-single.json');
        fakeThumbnailDownloads();

        Livewire::test('pages::music')->set('url', CALM_VIDEO_ID)->call('addMix');

        $mix = Mix::query()->sole();

        expect($mix->thumbnail_path)->not->toBeNull();
        Storage::disk('local')->assertExists($mix->thumbnail_path);
    });

    it('knows the length straight away when there is an API key', function (): void {
        fakeOEmbed();
        fakeVideosList('videos.list-single.json');
        fakeThumbnailDownloads();

        Livewire::test('pages::music')->set('url', CALM_VIDEO_ID)->call('addMix');

        expect(Mix::query()->sole()->duration_seconds)->toBe(377);
    });

    it('still adds a mix without an API key, leaving its length to the player', function (): void {
        config()->set('calm-tube.api_key');
        fakeOEmbed();
        fakeThumbnailDownloads();

        Livewire::test('pages::music')->set('url', CALM_VIDEO_ID)->call('addMix')->assertHasNoErrors();

        expect(Mix::query()->sole()->duration_seconds)->toBeNull();
    });

    it('refuses something that is not a video link', function (string $input): void {
        Livewire::test('pages::music')
            ->set('url', $input)
            ->call('addMix')
            ->assertHasErrors('url');

        expect(Mix::query()->count())->toBe(0);
    })->with([
        'a channel' => 'https://www.youtube.com/@quietrooms',
        'a playlist on its own' => 'https://www.youtube.com/playlist?list=PL123',
        'another site' => 'https://example.com/watch?v='.CALM_VIDEO_ID,
        'nothing' => '',
    ]);

    it('refuses a video YouTube will not let it play', function (): void {
        fakeOEmbed(401);

        Livewire::test('pages::music')
            ->set('url', CALM_VIDEO_ID)
            ->call('addMix')
            ->assertHasErrors('url')
            ->assertSee('can be played here');

        expect(Mix::query()->count())->toBe(0);
    });

    it('refuses a mix it already has, without asking YouTube', function (): void {
        Mix::factory()->create(['youtube_video_id' => CALM_VIDEO_ID, 'title' => 'Rainy Day Lofi']);

        Livewire::test('pages::music')
            ->set('url', 'https://youtu.be/'.CALM_VIDEO_ID)
            ->call('addMix')
            ->assertHasErrors('url')
            ->assertSee('Rainy Day Lofi');

        expect(Mix::query()->count())->toBe(1)
            ->and(requestsTo('oembed'))->toBe(0);
    });

    it('never puts a mix in the feed', function (): void {
        fakeOEmbed();
        fakeVideosList('videos.list-single.json');
        fakeThumbnailDownloads();

        Livewire::test('pages::music')->set('url', CALM_VIDEO_ID)->call('addMix');

        expect(Video::query()->count())->toBe(0);
    });
});

describe('the list', function (): void {
    it('shows the newest mix first', function (): void {
        Mix::factory()->create(['title' => 'Older Mix', 'created_at' => now()->subDay()]);
        Mix::factory()->create(['title' => 'Newer Mix']);

        Livewire::test('pages::music')->assertSeeInOrder(['Newer Mix', 'Older Mix']);
    });

    it('says what to do when there is nothing yet', function (): void {
        Livewire::test('pages::music')->assertSee('No music yet.');
    });

    it('shows where you stopped and how long the mix is', function (): void {
        Mix::factory()->partPlayed(1390)->create(['duration_seconds' => 7080]);

        Livewire::test('pages::music')
            ->assertSee('23:10 / 1:58:00')
            ->assertSee('data-resume="1390"', escape: false);
    });

    it('serves the archived thumbnail', function (): void {
        Storage::disk('local')->put('calm-tube/mixes/'.CALM_VIDEO_ID.'.jpg', youtubeFixture('thumbnail.jpg'));
        $mix = Mix::factory()->create([
            'youtube_video_id' => CALM_VIDEO_ID,
            'thumbnail_path' => 'calm-tube/mixes/'.CALM_VIDEO_ID.'.jpg',
        ]);

        $this->get(route('mixes.thumbnail', $mix))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    });
});

describe('playing', function (): void {
    it('remembers where you got to', function (): void {
        $mix = Mix::factory()->create();

        Livewire::test('pages::music')->call('saveProgress', $mix->id, 1390);

        expect($mix->fresh())
            ->resume_seconds->toBe(1390)
            ->last_played_at->not->toBeNull();
    });

    it('learns the length from the player when it did not know it', function (): void {
        $mix = Mix::factory()->withoutDuration()->create();

        Livewire::test('pages::music')->call('saveProgress', $mix->id, 10, 7080);

        expect($mix->fresh()->duration_seconds)->toBe(7080);
    });

    it('keeps the length it already had', function (): void {
        $mix = Mix::factory()->create(['duration_seconds' => 7080]);

        Livewire::test('pages::music')->call('saveProgress', $mix->id, 10, 9999);

        expect($mix->fresh()->duration_seconds)->toBe(7080);
    });

    it('plays in the official player, from youtube-nocookie', function (): void {
        Mix::factory()->create();

        $this->get(route('music'))
            ->assertSee('new YT.Player', escape: false)
            ->assertSee('youtube-nocookie.com', escape: false);
    });
});

describe('removing a mix', function (): void {
    it('removes the mix and its archived thumbnail', function (): void {
        Storage::disk('local')->put('calm-tube/mixes/abc.jpg', 'image');
        $mix = Mix::factory()->create(['thumbnail_path' => 'calm-tube/mixes/abc.jpg', 'title' => 'Gone Soon']);

        Livewire::test('pages::music')
            ->call('removeMix', $mix->id)
            ->assertSee('Removed "Gone Soon".');

        expect(Mix::query()->count())->toBe(0);
        Storage::disk('local')->assertMissing('calm-tube/mixes/abc.jpg');
    });
});
