<?php

use App\Models\ArchivedImage;
use App\Models\Mix;
use App\Models\Video;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
});

it('copies archived thumbnails, avatars and mix thumbnails into the database', function (): void {
    $channel = calmChannel();
    $channel->forceFill(['avatar_path' => 'calm-tube/avatars/'.$channel->youtube_channel_id.'.jpg'])->save();
    $video = Video::factory()->for($channel)->archived()->create();
    $mix = Mix::factory()->create(['thumbnail_path' => 'calm-tube/mixes/mix.jpg']);
    $bytes = youtubeFixture('thumbnail.jpg');

    foreach ([$channel->avatar_path, $video->thumbnail_path, $mix->thumbnail_path] as $path) {
        Storage::disk('local')->put($path, $bytes);
    }

    $this->artisan('calm:move-images')
        ->expectsOutputToContain('Moved 3 images, 0 already there.')
        ->assertSuccessful();

    expect(Storage::disk('images')->get($video->thumbnail_path))->toBe($bytes)
        ->and(Storage::disk('images')->exists($channel->avatar_path))->toBeTrue()
        ->and(Storage::disk('images')->exists($mix->thumbnail_path))->toBeTrue();
});

it('skips images already moved when run again', function (): void {
    $video = Video::factory()->for(calmChannel())->archived()->create();
    Storage::disk('local')->put($video->thumbnail_path, youtubeFixture('thumbnail.jpg'));

    $this->artisan('calm:move-images')->assertSuccessful();
    $this->artisan('calm:move-images')
        ->expectsOutputToContain('Moved 0 images, 1 already there.')
        ->assertSuccessful();

    expect(ArchivedImage::query()->count())->toBe(1);
});

it('reports a path whose file has gone missing without failing', function (): void {
    Video::factory()->for(calmChannel())->archived()->create();

    $this->artisan('calm:move-images')
        ->expectsOutputToContain('1 had no file to move')
        ->assertSuccessful();

    expect(ArchivedImage::query()->count())->toBe(0);
});
