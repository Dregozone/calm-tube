<?php

namespace App\Console\Commands;

use App\Http\Controllers\ImportController;
use Generator;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;

use function Laravel\Prompts\password;

/**
 * Sends this machine's library to the production site, once.
 *
 * The production host only receives code through git, so the data travels
 * over HTTPS instead: every table the library is made of, in foreign key
 * order, ids and all, into an endpoint that exists only while
 * CALM_IMPORT_TOKEN is set there and closes itself when the push finishes.
 *
 * Requests are kept small because the web server in front of PHP limits how
 * large a body it will accept, and archived images are most of the bytes.
 */
class PushToProductionCommand extends Command
{
    protected $signature = 'calm:push
        {url : The production site, e.g. https://calm.example.com}
        {--token= : The CALM_IMPORT_TOKEN set on the production site}
        {--max-kilobytes=512 : The largest request body to send}';

    protected $description = 'Push the local library to the production site through its one-time import endpoint';

    public function handle(): int
    {
        if ($this->imagesStillOnDisk()) {
            $this->error('The archived images are still files. Run `php artisan calm:move-images` first.');

            return self::FAILURE;
        }

        $token = (string) ($this->option('token') ?: password('Import token', required: true));
        $client = $this->client((string) $this->argument('url'), $token);

        try {
            $this->send($client, 'import/start', [], expecting: 'message');

            foreach (ImportController::TABLES as $table) {
                $this->pushTable($client, $table);
            }

            $counts = $this->send($client, 'import/finish', [], expecting: 'counts')->collect('counts');
        } catch (RequestException $exception) {
            $this->error($this->explain($exception->response));

            return self::FAILURE;
        } catch (UnexpectedValueException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(
            ['Table', 'Rows on production'],
            $counts->map(fn (mixed $count, string $table): array => [$table, $count])->values()->all(),
        );
        $this->info('Done. The import endpoint has closed itself; remove CALM_IMPORT_TOKEN from the production environment.');

        return self::SUCCESS;
    }

    private function pushTable(PendingRequest $client, string $table): void
    {
        $total = DB::table($table)->count();
        $this->line("{$table} ({$total})");

        $ignored = 0;
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($this->batches($table) as $batch) {
            $result = $this->send($client, 'import/rows', ['table' => $table, 'rows' => $batch], expecting: 'inserted');
            $ignored += (int) $result->json('received') - (int) $result->json('inserted');
            $bar->advance(count($batch));
        }

        $bar->finish();
        $this->newLine();

        if ($ignored > 0) {
            $this->warn("  {$ignored} {$table} ".Str::plural('row', $ignored).' already existed on production and were left alone.');
        }
    }

    /**
     * The table's rows, grouped so that no request body grows past the limit.
     * A single row larger than the limit still goes, on its own.
     *
     * @return Generator<int, list<array<string, mixed>>>
     */
    private function batches(string $table): Generator
    {
        $maximumBytes = max((int) $this->option('max-kilobytes'), 1) * 1024;
        $batch = [];
        $batchBytes = 0;

        foreach (DB::table($table)->orderBy('id')->lazyById(100) as $row) {
            $row = (array) $row;
            $bytes = strlen((string) json_encode($row));

            if ($batch !== [] && $batchBytes + $bytes > $maximumBytes) {
                yield $batch;

                $batch = [];
                $batchBytes = 0;
            }

            $batch[] = $row;
            $batchBytes += $bytes;
        }

        if ($batch !== []) {
            yield $batch;
        }
    }

    /**
     * A 200 is not enough: a misconfigured server can answer anything with
     * one, and a push that believes it succeeded is worse than one that fails.
     *
     * @param  array<string, mixed>  $data
     */
    private function send(PendingRequest $client, string $uri, array $data, string $expecting): Response
    {
        $response = $client->post($uri, $data)->throw();

        if ($response->json($expecting) === null) {
            throw new UnexpectedValueException(sprintf(
                "%s did not answer like Calm Tube's import endpoint: %s",
                $uri,
                Str::limit(strip_tags($response->body()), 300),
            ));
        }

        return $response;
    }

    private function client(string $url, string $token): PendingRequest
    {
        return Http::baseUrl(rtrim($url, '/'))
            ->withToken($token)
            ->acceptJson()
            ->timeout(60)
            ->retry(3, 2000, fn (Throwable $exception): bool => $exception instanceof RequestException
                && in_array($exception->response->status(), [429, 502, 503, 504], true), throw: false);
    }

    /**
     * Archived images that exist only as paths, because the files were never
     * copied into the database. Sending those would leave production with a
     * library of pictures it cannot show.
     */
    private function imagesStillOnDisk(): bool
    {
        if (DB::table('archived_images')->exists()) {
            return false;
        }

        return DB::table('channels')->whereNotNull('avatar_path')->exists()
            || DB::table('videos')->whereNotNull('thumbnail_path')->exists()
            || DB::table('mixes')->whereNotNull('thumbnail_path')->exists();
    }

    private function explain(Response $response): string
    {
        return match ($response->status()) {
            404 => 'The import endpoint is closed: check the URL, that CALM_IMPORT_TOKEN is set on production (32+ characters) and matches, and that no import has already finished.',
            409 => (string) $response->json('message'),
            413 => 'Production refused a request as too large. Try again with a smaller --max-kilobytes.',
            default => "Production answered {$response->status()}: ".Str::limit($response->body(), 300),
        };
    }
}
