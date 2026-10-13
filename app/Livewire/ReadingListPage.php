<?php

namespace App\Livewire;

use App\Exceptions\ReadingListException;
use App\Services\ReadingList\ReadingListSync;
use Livewire\Component;

/**
 * A page that reads the copy of the reading list: the videos and courses
 * pages. They share its Refresh button and the refresh on opening a stale
 * copy, and one fetch fills them all.
 */
abstract class ReadingListPage extends Component
{
    public ?string $status = null;

    public ?string $error = null;

    /**
     * Brings a copy more than a day old up to date once the page is already
     * on screen, so a slow or absent Personal Assistant never holds it up.
     */
    public function autoRefresh(ReadingListSync $sync): void
    {
        if (! $sync->isConfigured() || ! $sync->isStale()) {
            return;
        }

        $this->fetch($sync);
    }

    public function refresh(ReadingListSync $sync): void
    {
        $this->status = null;

        if ($this->fetch($sync)) {
            $this->status = __('Reading list refreshed.');
        }
    }

    protected function fetch(ReadingListSync $sync): bool
    {
        $this->error = null;

        try {
            $sync->refresh();
        } catch (ReadingListException $exception) {
            $this->error = $exception->getMessage();

            return false;
        }

        return true;
    }
}
