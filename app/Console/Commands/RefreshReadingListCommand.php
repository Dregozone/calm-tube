<?php

namespace App\Console\Commands;

use App\Exceptions\ReadingListException;
use App\Models\ReadingListItem;
use App\Services\ReadingList\ReadingListSync;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Fetches the YouTube videos and courses on Personal Assistant's reading
 * list, and archives any video not seen before.
 */
class RefreshReadingListCommand extends Command
{
    protected $signature = 'calm:reading-list';

    protected $description = "Fetch the YouTube videos and courses on Personal Assistant's reading list";

    public function handle(ReadingListSync $sync): int
    {
        if (! $sync->isConfigured()) {
            $this->warn(ReadingListException::notConfigured()->getMessage());

            return self::SUCCESS;
        }

        try {
            $sync->refresh();
        } catch (ReadingListException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $count = ReadingListItem::query()->pending()->count();

        $this->info(sprintf('%d %s to watch on the reading list.', $count, Str::plural('video', $count)));

        return self::SUCCESS;
    }
}
