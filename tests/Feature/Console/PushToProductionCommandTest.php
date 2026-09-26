<?php

use App\Models\ArchivedImage;
use App\Models\Video;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function fakeProductionImport(int $startStatus = 200): void
{
    Http::fake([
        'calm.test/import/start' => Http::response(['message' => 'This site already has channels. Nothing was imported.'], $startStatus),
        'calm.test/import/rows' => fn (Request $request) => Http::response([
            'received' => count($request['rows']),
            'inserted' => count($request['rows']),
        ]),
        'calm.test/import/finish' => Http::response(['counts' => ['channels' => 1, 'videos' => 2]]),
    ]);
}

it('pushes every library table in order, then closes the endpoint', function (): void {
    $channel = calmChannel(['avatar_path' => null]);
    Video::factory()->for($channel)->count(2)->create(['thumbnail_path' => null]);

    fakeProductionImport();

    $this->artisan('calm:push', ['url' => 'https://calm.test/', '--token' => 'secret'])
        ->expectsOutputToContain('The import endpoint has closed itself')
        ->assertSuccessful();

    $sent = Http::recorded()->map(fn (array $pair): string => str($pair[0]->url())->after('calm.test/').' '.($pair[0]->data()['table'] ?? ''));
    expect($sent->map(fn (string $line): string => trim($line))->all())->toBe([
        'import/start',
        'import/rows channels',
        'import/rows videos',
        'import/finish',
    ]);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer secret'));
    Http::assertSent(fn (Request $request): bool => ($request->data()['table'] ?? null) === 'channels'
        && $request->data()['rows'][0]['id'] === $channel->id
        && $request->data()['rows'][0]['youtube_channel_id'] === $channel->youtube_channel_id);
});

it('splits a table across requests to keep each one small', function (): void {
    $channel = calmChannel(['avatar_path' => null]);
    Video::factory()->for($channel)->count(3)->create(['thumbnail_path' => null]);
    ArchivedImage::factory()->count(3)->create();

    fakeProductionImport();

    $this->artisan('calm:push', ['url' => 'https://calm.test', '--token' => 'secret', '--max-kilobytes' => 1])
        ->assertSuccessful();

    Http::assertSentCount(1 + 1 + 3 + 3 + 1);
});

it('refuses to push while archived images are still files', function (): void {
    Video::factory()->for(calmChannel())->archived()->create();

    $this->artisan('calm:push', ['url' => 'https://calm.test', '--token' => 'secret'])
        ->expectsOutputToContain('calm:move-images')
        ->assertFailed();

    Http::assertNothingSent();
});

it('stops before sending anything when production already has a library', function (): void {
    calmChannel(['avatar_path' => null]);
    fakeProductionImport(startStatus: 409);

    $this->artisan('calm:push', ['url' => 'https://calm.test', '--token' => 'secret'])
        ->expectsOutputToContain('already has channels')
        ->assertFailed();

    Http::assertSentCount(1);
});

it('explains a closed endpoint', function (): void {
    Http::fake(['calm.test/*' => Http::response('', 404)]);

    $this->artisan('calm:push', ['url' => 'https://calm.test', '--token' => 'secret'])
        ->expectsOutputToContain('The import endpoint is closed')
        ->assertFailed();
});

it('fails rather than trusting a server that is not the import endpoint', function (): void {
    calmChannel(['avatar_path' => null]);
    Http::fake(['calm.test/*' => Http::response('<b>Fatal error</b>: something else answered', 200)]);

    $this->artisan('calm:push', ['url' => 'https://calm.test', '--token' => 'secret'])
        ->expectsOutputToContain("did not answer like Calm Tube's import endpoint")
        ->assertFailed();

    Http::assertSentCount(1);
});
