<?php

use App\Models\Video;
use Illuminate\Support\Facades\Storage;

it('serves the archived thumbnail', function (): void {
    Storage::fake('local');
    $video = Video::factory()->for(calmChannel())->archived()->create();
    Storage::disk('local')->put($video->thumbnail_path, youtubeFixture('thumbnail.jpg'));

    $response = $this->actingAs(calmUser())->get(route('thumbnails.show', $video));

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');
});

it('tells the browser the archived thumbnail never changes', function (): void {
    Storage::fake('local');
    $video = Video::factory()->for(calmChannel())->archived()->create();
    Storage::disk('local')->put($video->thumbnail_path, youtubeFixture('thumbnail.jpg'));

    $response = $this->actingAs(calmUser())->get(route('thumbnails.show', $video));

    expect($response->headers->get('Cache-Control'))->toContain('immutable');
});

it('falls back to YouTube when the thumbnail was never archived', function (): void {
    Storage::fake('local');
    $video = Video::factory()->for(calmChannel())->create([
        'thumbnail_path' => null,
        'thumbnail_url' => 'https://i.ytimg.com/vi/'.CALM_VIDEO_ID.'/hqdefault.jpg',
    ]);

    $this->actingAs(calmUser())
        ->get(route('thumbnails.show', $video))
        ->assertRedirect('https://i.ytimg.com/vi/'.CALM_VIDEO_ID.'/hqdefault.jpg');
});

it('falls back to YouTube when the archived file has gone missing', function (): void {
    Storage::fake('local');
    $video = Video::factory()->for(calmChannel())->archived()->create([
        'thumbnail_url' => 'https://i.ytimg.com/vi/'.CALM_VIDEO_ID.'/hqdefault.jpg',
    ]);

    $this->actingAs(calmUser())
        ->get(route('thumbnails.show', $video))
        ->assertRedirect('https://i.ytimg.com/vi/'.CALM_VIDEO_ID.'/hqdefault.jpg');
});

it('serves a placeholder when there is no thumbnail anywhere', function (): void {
    Storage::fake('local');
    $video = Video::factory()->for(calmChannel())->create([
        'thumbnail_path' => null,
        'thumbnail_url' => null,
    ]);

    $this->actingAs(calmUser())
        ->get(route('thumbnails.show', $video))
        ->assertOk();
});

it('returns 404 for a video that is not in the library', function (): void {
    $this->actingAs(calmUser())
        ->get(route('thumbnails.show', 'calmvid9999'))
        ->assertNotFound();
});

it('redirects a guest to the login page', function (): void {
    $video = Video::factory()->for(calmChannel())->create();

    $this->get(route('thumbnails.show', $video))->assertRedirect(route('login'));
});
