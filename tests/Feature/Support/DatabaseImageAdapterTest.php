<?php

use App\Models\ArchivedImage;
use App\Models\Video;
use Illuminate\Support\Facades\Storage;

it('keeps an archived image in the database as base64', function (): void {
    $bytes = youtubeFixture('thumbnail.jpg');

    Storage::disk('images')->put('calm-tube/thumbnails/abc.jpg', $bytes);

    $image = ArchivedImage::query()->sole();
    expect($image->path)->toBe('calm-tube/thumbnails/abc.jpg')
        ->and(base64_decode($image->contents))->toBe($bytes)
        ->and($image->mime_type)->toBe('image/jpeg')
        ->and($image->size)->toBe(strlen($bytes));
});

it('reads back exactly the bytes it was given', function (): void {
    $bytes = youtubeFixture('thumbnail.jpg');
    Storage::disk('images')->put('calm-tube/thumbnails/abc.jpg', $bytes);

    expect(Storage::disk('images')->get('calm-tube/thumbnails/abc.jpg'))->toBe($bytes)
        ->and(Storage::disk('images')->exists('calm-tube/thumbnails/abc.jpg'))->toBeTrue();
});

it('answers null for an image it does not have', function (): void {
    expect(Storage::disk('images')->get('calm-tube/thumbnails/missing.jpg'))->toBeNull()
        ->and(Storage::disk('images')->exists('calm-tube/thumbnails/missing.jpg'))->toBeFalse();
});

it('deletes an image', function (): void {
    Storage::disk('images')->put('calm-tube/thumbnails/abc.jpg', youtubeFixture('thumbnail.jpg'));

    Storage::disk('images')->delete('calm-tube/thumbnails/abc.jpg');

    expect(ArchivedImage::query()->count())->toBe(0);
});

it('lists only the images directly inside a folder unless asked to go deeper', function (): void {
    Storage::disk('images')->put('calm-tube/thumbnails/abc.jpg', 'x');
    Storage::disk('images')->put('calm-tube/avatars/def.jpg', 'x');

    expect(Storage::disk('images')->files('calm-tube/thumbnails'))->toBe(['calm-tube/thumbnails/abc.jpg'])
        ->and(Storage::disk('images')->files('calm-tube'))->toBe([])
        ->and(Storage::disk('images')->allFiles('calm-tube'))->toBe([
            'calm-tube/avatars/def.jpg',
            'calm-tube/thumbnails/abc.jpg',
        ]);
});

it('serves a thumbnail straight from the database', function (): void {
    $bytes = youtubeFixture('thumbnail.jpg');
    $video = Video::factory()->for(calmChannel())->archived()->create();
    Storage::disk('images')->put($video->thumbnail_path, $bytes);

    $response = $this->actingAs(calmUser())->get(route('thumbnails.show', $video));

    $response->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    expect($response->getContent())->toBe($bytes);
});
