<?php

use App\Models\Video;
use App\Services\YouTube\ImageArchiver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->archiver = app(ImageArchiver::class);
    Storage::fake('images');
});

function archivable(?string $thumbnailUrl): Video
{
    return Video::factory()->for(calmChannel())->create([
        'youtube_video_id' => CALM_VIDEO_ID,
        'thumbnail_url' => $thumbnailUrl,
        'thumbnail_path' => null,
    ]);
}

it('archives the thumbnail at the url YouTube returned', function (): void {
    fakeThumbnailDownloads();
    $video = archivable('https://i2.ytimg.com/vi/'.CALM_VIDEO_ID.'/hqdefault.jpg');

    $path = $this->archiver->archiveThumbnail($video);

    expect($path)->toBe('calm-tube/thumbnails/'.CALM_VIDEO_ID.'.jpg');
    Storage::disk('images')->assertExists($path);
    Http::assertSent(fn (Request $request): bool => $request->url() ===
        'https://i2.ytimg.com/vi/'.CALM_VIDEO_ID.'/hqdefault.jpg');
});

it('does not guess at a different url than the one it was given', function (): void {
    fakeThumbnailDownloads();
    $video = archivable('https://i2.ytimg.com/vi/'.CALM_VIDEO_ID.'/hqdefault.jpg');

    $this->archiver->archiveThumbnail($video);

    // Google asks applications to use thumbnail URLs exactly as returned, and
    // the sharded hosts in real feeds are not something a pattern would find.
    expect(requestsTo('maxresdefault'))->toBe(0)
        ->and(requestsTo('i.ytimg.com'))->toBe(0);
});

it('rejects the tiny grey image YouTube serves for a missing thumbnail', function (): void {
    Http::fake(['*.ytimg.com/*' => Http::response(
        youtubeFixture('thumbnail-placeholder.jpg'), 200, ['Content-Type' => 'image/jpeg']
    )]);
    $video = archivable('https://i2.ytimg.com/vi/'.CALM_VIDEO_ID.'/maxresdefault.jpg');

    expect($this->archiver->archiveThumbnail($video))->toBeNull();
    Storage::disk('images')->assertMissing('calm-tube/thumbnails/'.CALM_VIDEO_ID.'.jpg');
});

it('returns null when the image is not there', function (): void {
    Http::fake(['*.ytimg.com/*' => Http::response('', 404)]);
    $video = archivable('https://i2.ytimg.com/vi/'.CALM_VIDEO_ID.'/maxresdefault.jpg');

    expect($this->archiver->archiveThumbnail($video))->toBeNull();
    Storage::disk('images')->assertMissing('calm-tube/thumbnails/'.CALM_VIDEO_ID.'.jpg');
});

it('returns null when the download connection fails', function (): void {
    Http::fake(['*.ytimg.com/*' => Http::failedConnection()]);

    expect($this->archiver->archiveThumbnail(
        archivable('https://i2.ytimg.com/vi/'.CALM_VIDEO_ID.'/hqdefault.jpg')
    ))->toBeNull();
});

it('returns null when there is no url to archive', function (): void {
    expect($this->archiver->archiveThumbnail(archivable(null)))->toBeNull();
    Http::assertNothingSent();
});

it('does not download again for a video it has already archived', function (): void {
    $video = Video::factory()->for(calmChannel())->archived()->create();

    expect($this->archiver->archiveThumbnail($video))->toBe($video->thumbnail_path);
    Http::assertNothingSent();
});

it('archives a channel avatar', function (): void {
    fakeThumbnailDownloads();
    $channel = calmChannel([
        'avatar_url' => 'https://yt3.ggpht.com/calm-avatar=s800-c-k-c0x00ffffff-no-rj',
        'avatar_path' => null,
    ]);

    $path = $this->archiver->archiveAvatar($channel);

    expect($path)->toBe('calm-tube/avatars/'.CALM_CHANNEL_ID.'.jpg');
    Storage::disk('images')->assertExists($path);
});

it('returns null when a channel has no avatar url to archive', function (): void {
    expect($this->archiver->archiveAvatar(
        calmChannel(['avatar_url' => null, 'avatar_path' => null])
    ))->toBeNull();

    Http::assertNothingSent();
});
