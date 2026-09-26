<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Mix;
use App\Models\Video;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Copies images archived as files into the images disk.
 *
 * A one-off for a library built before images moved into the database. It
 * copies, never downloads: re-fetching from YouTube would archive whatever the
 * uploader has swapped the thumbnail to since. Safe to run again; anything
 * already moved is skipped.
 */
class MoveImagesToDatabaseCommand extends Command
{
    protected $signature = 'calm:move-images {--from=local : The disk the files were archived on}';

    protected $description = 'Copy archived image files into the database-backed images disk';

    public function handle(): int
    {
        $from = Storage::disk((string) $this->option('from'));
        $to = Storage::disk((string) config('calm-tube.images.disk'));

        $moved = 0;
        $skipped = 0;
        $missing = 0;

        $paths = $this->paths();
        $this->withProgressBar($paths, function (string $path) use ($from, $to, &$moved, &$skipped, &$missing): void {
            if ($to->exists($path)) {
                $skipped++;

                return;
            }

            $contents = $from->get($path);

            if ($contents === null) {
                $missing++;

                return;
            }

            $to->put($path, $contents);
            $moved++;
        });

        $this->newLine(2);
        $this->info(sprintf(
            'Moved %d %s, %d already there.%s',
            $moved,
            Str::plural('image', $moved),
            $skipped,
            $missing === 0 ? '' : " {$missing} had no file to move and will fall back to YouTube's copy.",
        ));

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, string>
     */
    private function paths(): Collection
    {
        return Channel::query()->whereNotNull('avatar_path')->pluck('avatar_path')
            ->merge(Video::query()->whereNotNull('thumbnail_path')->pluck('thumbnail_path'))
            ->merge(Mix::query()->whereNotNull('thumbnail_path')->pluck('thumbnail_path'))
            ->unique()
            ->values();
    }
}
