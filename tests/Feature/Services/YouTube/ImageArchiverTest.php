<?php

use App\Models\Channel;
use App\Models\Video;
use App\Services\YouTube\ImageArchiver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->archiver = app(ImageArchiver::class);
});

it('archives the highest resolution thumbnail available', function (): void {
    Storage::fake('local');
    fakeThumbnailDownloads();
    $video = Video::factory()->for(Channel::factory())->create(['youtube_video_id' => CALM_VIDEO_ID]);

    $path = $this->archiver->archiveThumbnail($video);

    expect($path)->toBe('calm-tube/thumbnails/'.CALM_VIDEO_ID.'.jpg');
    Storage::disk('local')->assertExists($path);
    Http::assertSent(fn (Request $request): bool => $request->url() ===
        'https://i.ytimg.com/vi/'.CALM_VIDEO_ID.'/maxresdefault.jpg');
});

it('falls back to the smaller thumbnail when maxres does not exist', function (): void {
    Storage::fake('local');
    fakeThumbnailDownloadsWithoutMaxres();
    $video = Video::factory()->for(Channel::factory())->create(['youtube_video_id' => CALM_VIDEO_ID]);

    $path = $this->archiver->archiveThumbnail($video);

    expect($path)->not->toBeNull();
    Storage::disk('local')->assertExists($path);
    Http::assertSent(fn (Request $request): bool => $request->url() ===
        'https://i.ytimg.com/vi/'.CALM_VIDEO_ID.'/mqdefault.jpg');
});

it('rejects the tiny grey image YouTube serves in place of a missing thumbnail', function (): void {
    Storage::fake('local');
    Http::fake([
        'i.ytimg.com/*/maxresdefault.jpg' => Http::response(
            youtubeFixture('thumbnail-placeholder.jpg'), 200, ['Content-Type' => 'image/jpeg']
        ),
        'i.ytimg.com/*/mqdefault.jpg' => Http::response(
            youtubeFixture('thumbnail.jpg'), 200, ['Content-Type' => 'image/jpeg']
        ),
    ]);
    $video = Video::factory()->for(Channel::factory())->create(['youtube_video_id' => CALM_VIDEO_ID]);

    $this->archiver->archiveThumbnail($video);

    expect(Storage::disk('local')->size('calm-tube/thumbnails/'.CALM_VIDEO_ID.'.jpg'))
        ->toBeGreaterThan(1024);
});

it('returns null and stores nothing when every variant fails', function (): void {
    Storage::fake('local');
    Http::fake(['i.ytimg.com/*' => Http::response('', 404)]);
    $video = Video::factory()->for(Channel::factory())->create(['youtube_video_id' => CALM_VIDEO_ID]);

    $path = $this->archiver->archiveThumbnail($video);

    expect($path)->toBeNull();
    Storage::disk('local')->assertMissing('calm-tube/thumbnails/'.CALM_VIDEO_ID.'.jpg');
});

it('returns null when the download connection fails', function (): void {
    Storage::fake('local');
    Http::fake(['i.ytimg.com/*' => Http::failedConnection()]);
    $video = Video::factory()->for(Channel::factory())->create(['youtube_video_id' => CALM_VIDEO_ID]);

    expect($this->archiver->archiveThumbnail($video))->toBeNull();
});

it('does not download again for a video it has already archived', function (): void {
    Storage::fake('local');
    $video = Video::factory()->for(Channel::factory())->archived()->create([
        'youtube_video_id' => CALM_VIDEO_ID,
    ]);
    Storage::disk('local')->put($video->thumbnail_path, 'already here');

    $this->archiver->archiveThumbnail($video);

    Http::assertNothingSent();
});

it('archives a channel avatar', function (): void {
    Storage::fake('local');
    fakeThumbnailDownloads();
    $channel = Channel::factory()->create([
        'youtube_channel_id' => CALM_CHANNEL_ID,
        'avatar_url' => 'https://yt3.ggpht.com/calm-avatar=s800-c-k-c0x00ffffff-no-rj',
        'avatar_path' => null,
    ]);

    $path = $this->archiver->archiveAvatar($channel);

    expect($path)->toBe('calm-tube/avatars/'.CALM_CHANNEL_ID.'.jpg');
    Storage::disk('local')->assertExists($path);
});

it('returns null when a channel has no avatar url to archive', function (): void {
    Storage::fake('local');
    $channel = Channel::factory()->create(['avatar_url' => null, 'avatar_path' => null]);

    expect($this->archiver->archiveAvatar($channel))->toBeNull();
    Http::assertNothingSent();
});
