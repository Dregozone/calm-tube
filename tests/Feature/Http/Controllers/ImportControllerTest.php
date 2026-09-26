<?php

use App\Http\Controllers\ImportController;
use App\Models\Channel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

const IMPORT_TOKEN = 'a-long-enough-import-token-for-the-tests-0123456789';

beforeEach(function (): void {
    config()->set('calm-tube.import.token', IMPORT_TOKEN);
});

/**
 * A channels row as the sending SQLite database holds it.
 *
 * @return array<string, mixed>
 */
function importedChannelRow(int $id = 7, string $title = 'Imported'): array
{
    return [
        'id' => $id,
        'youtube_channel_id' => 'UCimported'.$id,
        'title' => $title,
        'is_enabled' => 1,
        'created_at' => '2026-09-22 18:13:24',
        'updated_at' => '2026-09-22 18:13:24',
    ];
}

it('is not there when no token is configured', function (?string $token): void {
    config()->set('calm-tube.import.token', $token);

    $this->withToken((string) $token)->postJson(route('import.start'))->assertNotFound();
})->with([
    'unset' => null,
    'too short' => 'short-token',
]);

it('is not there without the right token', function (?string $token): void {
    $request = $token === null ? $this : $this->withToken($token);

    $request->postJson(route('import.start'))->assertNotFound();
})->with([
    'missing' => null,
    'wrong' => 'a-long-enough-but-wrong-import-token-0123456789-xx',
]);

it('is not there once an import has finished', function (): void {
    $this->withToken(IMPORT_TOKEN)->postJson(route('import.finish'))->assertOk();

    $this->withToken(IMPORT_TOKEN)->postJson(route('import.start'))->assertNotFound();
    $this->withToken(IMPORT_TOKEN)
        ->postJson(route('import.rows'), ['table' => 'channels', 'rows' => [importedChannelRow()]])
        ->assertNotFound();

    expect(Cache::has(ImportController::COMPLETED_KEY))->toBeTrue()
        ->and(Channel::query()->count())->toBe(0);
});

it('refuses to start on top of an existing library', function (): void {
    calmChannel();

    $this->withToken(IMPORT_TOKEN)->postJson(route('import.start'))->assertConflict();
});

it('is ready to start on an empty site', function (): void {
    $this->withToken(IMPORT_TOKEN)->postJson(route('import.start'))->assertOk();
});

it('imports rows exactly as sent, ids and all', function (): void {
    $this->withToken(IMPORT_TOKEN)
        ->postJson(route('import.rows'), ['table' => 'channels', 'rows' => [importedChannelRow()]])
        ->assertOk()
        ->assertJson(['received' => 1, 'inserted' => 1]);

    $channel = Channel::query()->sole();
    expect($channel->id)->toBe(7)
        ->and($channel->title)->toBe('Imported')
        ->and($channel->created_at->toDateTimeString())->toBe('2026-09-22 18:13:24');
});

it('keeps every string exactly as sent', function (): void {
    $title = "  Sometimes, you run away.\u{2060}";

    $this->withToken(IMPORT_TOKEN)
        ->postJson(route('import.rows'), ['table' => 'channels', 'rows' => [
            [...importedChannelRow(title: $title), 'custom_name' => ''],
        ]])
        ->assertOk();

    $channel = Channel::query()->sole();
    expect($channel->title)->toBe($title)
        ->and($channel->custom_name)->toBe('');
});

it('never overwrites a row that is already there', function (): void {
    $this->withToken(IMPORT_TOKEN)
        ->postJson(route('import.rows'), ['table' => 'channels', 'rows' => [importedChannelRow()]]);

    $this->withToken(IMPORT_TOKEN)
        ->postJson(route('import.rows'), ['table' => 'channels', 'rows' => [importedChannelRow(title: 'Changed')]])
        ->assertOk()
        ->assertJson(['received' => 1, 'inserted' => 0]);

    expect(Channel::query()->sole()->title)->toBe('Imported');
});

it('drops columns the table does not have', function (): void {
    $this->withToken(IMPORT_TOKEN)
        ->postJson(route('import.rows'), ['table' => 'channels', 'rows' => [
            [...importedChannelRow(), 'not_a_column' => 'x'],
        ]])
        ->assertOk();

    expect(Channel::query()->count())->toBe(1);
});

it('only accepts the library tables', function (string $table): void {
    $this->withToken(IMPORT_TOKEN)
        ->postJson(route('import.rows'), ['table' => $table, 'rows' => [['id' => 99, 'email' => 'x@example.com']]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('table');

    expect(DB::table('users')->count())->toBe(0);
})->with(['users', 'sessions', 'passkeys']);
