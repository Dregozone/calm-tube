<?php

use App\Models\Channel;
use Illuminate\Support\Facades\Storage;

it('serves the archived avatar', function (): void {
    Storage::fake('images');
    $channel = calmChannel(['avatar_path' => 'calm-tube/avatars/'.CALM_CHANNEL_ID.'.jpg']);
    Storage::disk('images')->put($channel->avatar_path, youtubeFixture('thumbnail.jpg'));

    $this->actingAs(calmUser())
        ->get(route('avatars.show', $channel))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');
});

it('falls back to YouTube when the avatar was never archived', function (): void {
    Storage::fake('images');
    $channel = calmChannel([
        'avatar_path' => null,
        'avatar_url' => 'https://yt3.ggpht.com/calm-avatar=s800-c-k-c0x00ffffff-no-rj',
    ]);

    $this->actingAs(calmUser())
        ->get(route('avatars.show', $channel))
        ->assertRedirect('https://yt3.ggpht.com/calm-avatar=s800-c-k-c0x00ffffff-no-rj');
});

it('serves a placeholder when there is no avatar anywhere', function (): void {
    Storage::fake('images');
    $channel = calmChannel(['avatar_path' => null, 'avatar_url' => null]);

    $this->actingAs(calmUser())
        ->get(route('avatars.show', $channel))
        ->assertOk();
});

it('returns 404 for a channel that is not followed', function (): void {
    $this->actingAs(calmUser())
        ->get(route('avatars.show', 'UCnosuchchannelabcdefghi'))
        ->assertNotFound();
});

it('redirects a guest to the login page', function (): void {
    $channel = Channel::factory()->create();

    $this->get(route('avatars.show', $channel))->assertRedirect(route('login'));
});
