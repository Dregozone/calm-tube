<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * Receives the library pushed by `calm:push`, once.
 *
 * Rows arrive exactly as the sending database holds them, ids included, so
 * every relationship survives the move. They are only ever added: a row whose
 * id is already taken is ignored, never overwritten, which also makes an
 * interrupted push safe to run again.
 */
class ImportController extends Controller
{
    public const string COMPLETED_KEY = 'calm-tube.import.completed';

    /**
     * The tables that make up the library, in the order they must arrive.
     *
     * @var list<string>
     */
    public const array TABLES = [
        'channels',
        'videos',
        'mixes',
        'channel_digests',
        'archived_images',
    ];

    /**
     * Refuses to import on top of a library that already exists.
     */
    public function start(): JsonResponse
    {
        if (DB::table('channels')->exists()) {
            return response()->json(['message' => 'This site already has channels. Nothing was imported.'], 409);
        }

        return response()->json(['message' => 'Ready.']);
    }

    public function rows(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'table' => ['required', 'string', Rule::in(self::TABLES)],
            'rows' => ['required', 'array', 'max:5000'],
            'rows.*' => ['required', 'array'],
        ]);

        $table = $validated['table'];
        $columns = Schema::getColumnListing($table);

        /** @var list<array<string, mixed>> $rows */
        $rows = array_map(
            fn (array $row): array => Arr::only($row, $columns),
            array_values($validated['rows']),
        );

        $inserted = DB::table($table)->insertOrIgnore($rows);

        return response()->json(['received' => count($rows), 'inserted' => $inserted]);
    }

    /**
     * Closes the endpoint for good, whether or not the token is ever removed.
     */
    public function finish(): JsonResponse
    {
        Cache::forever(self::COMPLETED_KEY, now()->toIso8601String());

        return response()->json([
            'message' => 'Import complete. The endpoint is now closed.',
            'counts' => collect(self::TABLES)->mapWithKeys(fn (string $table): array => [
                $table => DB::table($table)->count(),
            ])->all(),
        ]);
    }
}
