<?php

namespace App\Jobs;

use App\Enums\RefreshTrigger;
use App\Models\Channel;
use App\Services\ChannelRefresher;
use App\Support\RefreshResult;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Refreshing one channel, as a job.
 *
 * Always invoked with dispatchSync(): this runs on one machine for one person,
 * and a queue worker that has to be running is the likeliest way for the app to
 * look broken.
 *
 * Deliberately not a ShouldQueue. Laravel routes a ShouldQueue job dispatched
 * synchronously through the sync queue driver, which returns the queue's push
 * result rather than the handler's, and callers need the RefreshResult to
 * report what happened. Adding the interface, and swapping dispatchSync for
 * dispatch, is the switch to real queueing.
 */
class RefreshChannel
{
    use Queueable;

    public function __construct(
        public Channel $channel,
        public RefreshTrigger $trigger = RefreshTrigger::Manual,
    ) {}

    public function handle(ChannelRefresher $refresher): RefreshResult
    {
        return $refresher->refresh($this->channel, $this->trigger);
    }
}
