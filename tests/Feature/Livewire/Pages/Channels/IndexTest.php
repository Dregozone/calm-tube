<?php

use App\Jobs\RefreshChannel;
use App\Models\Channel;
use App\Models\Video;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(calmUser());
});

describe('adding a channel', function (): void {
    it('adds a channel pasted as a handle', function (): void {
        Storage::fake('local');
        fakeChannelsList();
        fakeSuccessfulRefresh('feed-single-entry.xml', 'videos.list-single.json');

        Livewire::test('pages::channels.index')
            ->set('input', '@practicalengineering')
            ->call('addChannel')
            ->assertHasNoErrors();

        $channel = Channel::sole();

        expect($channel->youtube_channel_id)->toBe(CALM_CHANNEL_ID)
            ->and($channel->title)->toBe(CALM_CHANNEL_TITLE)
            ->and($channel->is_enabled)->toBeTrue();
    });

    it('fetches the channel videos straight away, so the feed is never empty', function (): void {
        Storage::fake('local');
        fakeChannelsList();
        fakeSuccessfulRefresh('feed-single-entry.xml', 'videos.list-single.json');

        Livewire::test('pages::channels.index')
            ->set('input', '@practicalengineering')
            ->call('addChannel');

        expect(Video::count())->toBe(1);
    });

    it('archives the channel avatar', function (): void {
        Storage::fake('local');
        fakeChannelsList();
        fakeSuccessfulRefresh('feed-single-entry.xml', 'videos.list-single.json');

        Livewire::test('pages::channels.index')
            ->set('input', '@practicalengineering')
            ->call('addChannel');

        expect(Channel::sole()->avatar_path)->not->toBeNull();
    });

    it('says how many videos it imported', function (): void {
        Storage::fake('local');
        fakeChannelsList();
        fakeSuccessfulRefresh('feed-single-entry.xml', 'videos.list-single.json');

        Livewire::test('pages::channels.index')
            ->set('input', '@practicalengineering')
            ->call('addChannel')
            ->assertSee(CALM_CHANNEL_TITLE);
    });
});

describe('add form errors', function (): void {
    it('requires something to be pasted', function (): void {
        Livewire::test('pages::channels.index')
            ->set('input', '')
            ->call('addChannel')
            ->assertHasErrors(['input' => 'required']);

        expect(Channel::count())->toBe(0);
        Http::assertNothingSent();
    });

    it('explains what it accepts when the input makes no sense', function (): void {
        Livewire::test('pages::channels.index')
            ->set('input', 'please add practical engineering for me')
            ->call('addChannel')
            ->assertHasErrors('input')
            ->assertSee("doesn't look like a YouTube channel");

        expect(Channel::count())->toBe(0);
        Http::assertNothingSent();
    });

    it('refuses a channel that is already followed', function (): void {
        fakeChannelsList();
        calmChannel();

        Livewire::test('pages::channels.index')
            ->set('input', '@practicalengineering')
            ->call('addChannel')
            ->assertHasErrors('input')
            ->assertSee('already follow');

        expect(Channel::count())->toBe(1);
    });

    it('offers to enable a channel that is already followed but disabled', function (): void {
        fakeChannelsList();
        calmChannel(['is_enabled' => false]);

        Livewire::test('pages::channels.index')
            ->set('input', '@practicalengineering')
            ->call('addChannel')
            ->assertSee('already added but disabled');
    });

    it('says when no such channel exists', function (): void {
        fakeChannelsList('empty-items.json');

        Livewire::test('pages::channels.index')
            ->set('input', '@nobodyhere')
            ->call('addChannel')
            ->assertHasErrors('input')
            ->assertSee('No channel found');

        expect(Channel::count())->toBe(0);
    });

    it('suggests an alternative for a legacy custom url', function (): void {
        Livewire::test('pages::channels.index')
            ->set('input', 'https://www.youtube.com/c/PracticalEngineering')
            ->call('addChannel')
            ->assertHasErrors('input')
            ->assertSee('@handle');

        Http::assertNothingSent();
    });

    it('explains that a handle needs an API key', function (): void {
        config()->set('calm-tube.api_key');

        Livewire::test('pages::channels.index')
            ->set('input', '@practicalengineering')
            ->call('addChannel')
            ->assertHasErrors('input')
            ->assertSee('API key');

        expect(Channel::count())->toBe(0);
    });

    it('still adds a channel id without an API key', function (): void {
        Storage::fake('local');
        config()->set('calm-tube.api_key');
        fakeFeed('feed-single-entry.xml');
        fakeShortsProbe();
        fakeThumbnailDownloads();

        Livewire::test('pages::channels.index')
            ->set('input', CALM_CHANNEL_ID)
            ->call('addChannel')
            ->assertHasNoErrors();

        expect(Channel::sole()->title)->toBe(CALM_CHANNEL_TITLE);
    });

    it('says when the daily quota is used up', function (): void {
        fakeChannelsList('quota-exceeded.json', 403);

        Livewire::test('pages::channels.index')
            ->set('input', '@practicalengineering')
            ->call('addChannel')
            ->assertHasErrors('input')
            ->assertSee('quota');

        expect(Channel::count())->toBe(0);
    });

    it('says when YouTube cannot be reached', function (): void {
        Http::fake(['www.googleapis.com/*' => Http::failedConnection()]);

        Livewire::test('pages::channels.index')
            ->set('input', '@practicalengineering')
            ->call('addChannel')
            ->assertHasErrors('input')
            ->assertSee("Couldn't reach YouTube");

        expect(Channel::count())->toBe(0);
    });
});

describe('listing channels', function (): void {
    it('shows each channel with how many videos it has', function (): void {
        $channel = calmChannel();
        Video::factory()->for($channel)->count(3)->create();

        Livewire::test('pages::channels.index')
            ->assertSee(CALM_CHANNEL_TITLE)
            ->assertSee('3 videos');
    });

    it('shows the custom name in place of the YouTube name', function (): void {
        calmChannel(['custom_name' => 'Bridges Guy']);

        Livewire::test('pages::channels.index')
            ->assertSee('Bridges Guy')
            ->assertDontSee(CALM_CHANNEL_TITLE);
    });

    it('shows when each channel was last refreshed', function (): void {
        $this->travelTo('2026-03-15 12:00:00');
        calmChannel(['last_refreshed_at' => now()->subMinutes(14)]);

        Livewire::test('pages::channels.index')->assertSee('14 minutes ago');
    });

    it('shows the error from a channel whose last refresh failed', function (): void {
        Channel::factory()->failing()->create();

        Livewire::test('pages::channels.index')->assertSee('failed');
    });

    it('marks a disabled channel as hidden from the feed', function (): void {
        Channel::factory()->disabled()->create();

        Livewire::test('pages::channels.index')->assertSee('disabled');
    });

    it('invites you to add your first channel when you follow none', function (): void {
        Livewire::test('pages::channels.index')->assertSee('Add a channel');
    });
});

describe('managing a channel', function (): void {
    it('disables a channel without losing its videos', function (): void {
        $channel = calmChannel();
        Video::factory()->for($channel)->count(3)->create();

        Livewire::test('pages::channels.index')->call('toggleEnabled', $channel->id);

        expect($channel->fresh()->is_enabled)->toBeFalse()
            ->and($channel->videos()->count())->toBe(3)
            ->and(Video::inFeed()->count())->toBe(0);
    });

    it('enables a disabled channel again', function (): void {
        $channel = Channel::factory()->disabled()->create();
        Video::factory()->for($channel)->create();

        Livewire::test('pages::channels.index')->call('toggleEnabled', $channel->id);

        expect($channel->fresh()->is_enabled)->toBeTrue()
            ->and(Video::inFeed()->count())->toBe(1);
    });

    it('saves a custom display name', function (): void {
        $channel = calmChannel();

        Livewire::test('pages::channels.index')
            ->call('edit', $channel->id)
            ->set('customName', 'Bridges Guy')
            ->call('save');

        expect($channel->fresh()->custom_name)->toBe('Bridges Guy');
    });

    it('clears the custom name when it is emptied', function (): void {
        $channel = calmChannel(['custom_name' => 'Bridges Guy']);

        Livewire::test('pages::channels.index')
            ->call('edit', $channel->id)
            ->set('customName', '')
            ->call('save');

        expect($channel->fresh()->custom_name)->toBeNull()
            ->and($channel->fresh()->display_name)->toBe(CALM_CHANNEL_TITLE);
    });

    it('refreshes a single channel', function (): void {
        Storage::fake('local');
        fakeSuccessfulRefresh('feed-single-entry.xml', 'videos.list-single.json');
        $channel = calmChannel();
        $other = Channel::factory()->create();

        Livewire::test('pages::channels.index')->call('refreshChannel', $channel->id);

        expect($channel->videos()->count())->toBe(1)
            ->and($other->videos()->count())->toBe(0);
    });

    it('refreshes a disabled channel when asked directly', function (): void {
        Bus::fake([RefreshChannel::class]);
        $channel = Channel::factory()->disabled()->create();

        Livewire::test('pages::channels.index')->call('refreshChannel', $channel->id);

        Bus::assertDispatchedSync(RefreshChannel::class);
    });
});

describe('deleting a channel', function (): void {
    it('warns exactly what will be lost', function (): void {
        $channel = calmChannel();
        Video::factory()->for($channel)->count(5)->create();
        Video::factory()->for($channel)->watched()->count(2)->create();

        Livewire::test('pages::channels.index')
            ->call('confirmDelete', $channel->id)
            ->assertSee('7 archived videos')
            ->assertSee('2 marked as watched')
            ->assertSee('disable');
    });

    it('deletes the channel and everything archived with it', function (): void {
        Storage::fake('local');
        $channel = calmChannel();
        $video = Video::factory()->for($channel)->archived()->create();
        Storage::disk('local')->put($video->thumbnail_path, 'archived bytes');

        Livewire::test('pages::channels.index')->call('delete', $channel->id);

        $this->assertModelMissing($channel);
        $this->assertModelMissing($video);
        Storage::disk('local')->assertMissing($video->thumbnail_path);
    });

    it('leaves other channels untouched', function (): void {
        $channel = calmChannel();
        $kept = Channel::factory()->create();
        $keptVideo = Video::factory()->for($kept)->create();

        Livewire::test('pages::channels.index')->call('delete', $channel->id);

        $this->assertModelExists($kept);
        $this->assertModelExists($keptVideo);
    });
});

it('redirects a guest to the login page', function (): void {
    auth()->logout();

    $this->get(route('channels.index'))->assertRedirect(route('login'));
});

describe('finishing early from the channel list', function (): void {
    it('saves how long the channel outro plug runs', function (): void {
        $channel = calmChannel();

        Livewire::test('pages::channels.index')
            ->call('edit', $channel->id)
            ->set('outroSeconds', '15')
            ->call('save')
            ->assertHasNoErrors();

        expect($channel->fresh()->outro_seconds)->toBe(15);
    });

    it('loads the outro already set', function (): void {
        $channel = calmChannel(['outro_seconds' => 20]);

        Livewire::test('pages::channels.index')
            ->call('edit', $channel->id)
            ->assertSet('outroSeconds', '20');
    });

    it('goes back to watching to the end when emptied', function (): void {
        $channel = calmChannel(['outro_seconds' => 20]);

        Livewire::test('pages::channels.index')
            ->call('edit', $channel->id)
            ->set('outroSeconds', '')
            ->call('save');

        expect($channel->fresh()->outro_seconds)->toBeNull();
    });

    it('refuses an outro that is not a sensible number of seconds', function (string $seconds): void {
        $channel = calmChannel();

        Livewire::test('pages::channels.index')
            ->call('edit', $channel->id)
            ->set('outroSeconds', $seconds)
            ->call('save')
            ->assertHasErrors('outroSeconds');

        expect($channel->fresh()->outro_seconds)->toBeNull();
    })->with(['zero' => '0', 'too long' => '121', 'not a number' => 'soon']);
});

describe('playback speed from the channel list', function (): void {
    it('saves a speed for every video from the channel', function (): void {
        $channel = calmChannel();

        Livewire::test('pages::channels.index')
            ->call('edit', $channel->id)
            ->set('playbackRate', '1.5')
            ->call('save');

        expect($channel->fresh()->playback_rate)->toBe(1.5);
    });

    it('loads the speed already chosen', function (): void {
        $channel = calmChannel(['playback_rate' => 2.0]);

        Livewire::test('pages::channels.index')
            ->call('edit', $channel->id)
            ->assertSet('playbackRate', '2');
    });

    it('returns a channel to normal speed', function (): void {
        $channel = calmChannel(['playback_rate' => 2.0]);

        Livewire::test('pages::channels.index')
            ->call('edit', $channel->id)
            ->set('playbackRate', '')
            ->call('save');

        expect($channel->fresh()->playback_rate)->toBeNull();
    });

    it('refuses a speed the player would not accept', function (): void {
        $channel = calmChannel();

        Livewire::test('pages::channels.index')
            ->call('edit', $channel->id)
            ->set('playbackRate', '9')
            ->call('save')
            ->assertHasErrors('playbackRate');

        expect($channel->fresh()->playback_rate)->toBeNull();
    });

    it('shows the speed on the channel row', function (): void {
        calmChannel(['playback_rate' => 2.0]);

        Livewire::test('pages::channels.index')->assertSee('2×', escape: false);
    });
});

describe('the delete confirmation', function (): void {
    it('offers disabling as the way out that loses nothing', function (): void {
        $channel = calmChannel();

        Livewire::test('pages::channels.index')
            ->call('confirmDelete', $channel->id)
            ->call('toggleEnabled', $channel->id);

        expect($channel->fresh()->is_enabled)->toBeFalse()
            ->and(Channel::query()->count())->toBe(1);
    });

    it('does not offer disabling a channel that is already disabled', function (): void {
        $channel = Channel::factory()->disabled()->create();

        Livewire::test('pages::channels.index')
            ->call('confirmDelete', $channel->id)
            ->assertDontSee('Disable instead');
    });
});

describe('unwatched counts', function (): void {
    it('shows how much of each channel is still waiting', function (): void {
        $channel = calmChannel();
        Video::factory()->for($channel)->count(3)->create();
        Video::factory()->for($channel)->watched()->create();

        Livewire::test('pages::channels.index')->assertSee('3 unwatched');
    });

    it('counts the way the feed counts, so the number matches the cards', function (): void {
        $channel = calmChannel();
        Video::factory()->for($channel)->create();
        Video::factory()->for($channel)->short()->count(4)->create();

        Livewire::test('pages::channels.index')->assertSee('1 unwatched');
    });

    it('says you are caught up rather than showing a zero', function (): void {
        $channel = calmChannel();
        Video::factory()->for($channel)->watched()->count(2)->create();

        Livewire::test('pages::channels.index')
            ->assertSee('all caught up')
            ->assertDontSee('0 unwatched');
    });
});

describe('sampling a noisy channel', function (): void {
    it('saves a limit on how much of the channel reaches the feed', function (): void {
        $channel = calmChannel();

        Livewire::test('pages::channels.index')
            ->call('edit', $channel->id)
            ->set('sampleLimit', '3')
            ->call('save');

        expect($channel->fresh()->sample_limit)->toBe(3);
    });

    it('applies the limit to what is already here, not only to the next refresh', function (): void {
        $channel = calmChannel();

        foreach ([71, 12, 4, 3, 2] as $index => $minutes) {
            Video::factory()->for($channel)->create([
                'duration_seconds' => $minutes * 60,
                'published_at' => '2026-09-22 1'.$index.':00:00',
            ]);
        }

        Livewire::test('pages::channels.index')
            ->call('edit', $channel->id)
            ->set('sampleLimit', '3')
            ->call('save');

        expect(Video::inFeed()->count())->toBe(3);
    });

    it('puts everything back when the limit is cleared', function (): void {
        $channel = calmChannel(['sample_limit' => 1]);
        Video::factory()->for($channel)->setAside()->count(3)->create();

        Livewire::test('pages::channels.index')
            ->call('edit', $channel->id)
            ->set('sampleLimit', '')
            ->call('save');

        expect($channel->fresh()->sample_limit)->toBeNull()
            ->and(Video::query()->setAside()->count())->toBe(0);
    });

    it('loads the limit already set', function (): void {
        $channel = calmChannel(['sample_limit' => 5]);

        Livewire::test('pages::channels.index')
            ->call('edit', $channel->id)
            ->assertSet('sampleLimit', '5');
    });

    it('refuses a limit that is not a sensible number', function (): void {
        $channel = calmChannel();

        Livewire::test('pages::channels.index')
            ->call('edit', $channel->id)
            ->set('sampleLimit', '0')
            ->call('save')
            ->assertHasErrors('sampleLimit');

        expect($channel->fresh()->sample_limit)->toBeNull();
    });

    it('says on the row how much of the channel is reaching you', function (): void {
        $channel = calmChannel(['sample_limit' => 3]);
        Video::factory()->for($channel)->setAside()->count(11)->create();

        Livewire::test('pages::channels.index')
            ->assertSee('keeping 3 a day')
            ->assertSee('11 set aside');
    });

    it('saves a weekly pick with what you want from the channel', function (): void {
        $channel = calmChannel();

        Livewire::test('pages::channels.index')
            ->call('edit', $channel->id)
            ->set('sampleLimit', '2')
            ->set('samplePeriod', 'week')
            ->set('sampleNote', '  Long-form teaching on offers.  ')
            ->call('save')
            ->assertHasNoErrors();

        $channel->refresh();

        expect($channel->isPickedWeekly())->toBeTrue()
            ->and($channel->sample_note)->toBe('Long-form teaching on offers.');
    });

    it('holds the current week as soon as a weekly pick is saved', function (): void {
        $channel = calmChannel();
        Video::factory()->for($channel)->create(['published_at' => now()]);

        Livewire::test('pages::channels.index')
            ->call('edit', $channel->id)
            ->set('sampleLimit', '2')
            ->set('samplePeriod', 'week')
            ->call('save');

        expect(Video::inFeed()->count())->toBe(0);
    });

    it('says on the row that a channel is picked weekly', function (): void {
        calmChannel(['sample_limit' => 2, 'sample_period' => 'week']);

        Livewire::test('pages::channels.index')->assertSee('picking 2 a week');
    });

    it('says nothing about sampling on a channel that is not sampled', function (): void {
        calmChannel();

        Livewire::test('pages::channels.index')->assertDontSee('a day');
    });
});
