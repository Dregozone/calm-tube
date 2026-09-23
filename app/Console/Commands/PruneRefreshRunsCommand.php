<?php

namespace App\Console\Commands;

use App\Models\RefreshRun;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Trims the refresh history.
 *
 * The only thing in this app that is ever deleted on a schedule. Refresh runs
 * are operational history — what happened, when, and whether it worked — not
 * part of your archive, and a channel refreshed hourly writes 8,760 rows a
 * year with nothing to say for most of them.
 */
class PruneRefreshRunsCommand extends Command
{
    protected $signature = 'calm:prune-runs {--days= : Keep runs newer than this many days}';

    protected $description = 'Remove refresh history older than the retention window';

    public function handle(): int
    {
        $days = $this->days();
        $cutoff = now()->subDays($days);

        $stale = RefreshRun::query()->where('started_at', '<', $cutoff);
        $count = $stale->count();

        if ($count === 0) {
            $this->info("Nothing to prune. No refresh history is older than {$days} days.");

            return self::SUCCESS;
        }

        $stale->delete();

        $this->info(sprintf(
            'Pruned %d refresh %s older than %d days.',
            $count,
            Str::plural('run', $count),
            $days,
        ));

        return self::SUCCESS;
    }

    private function days(): int
    {
        $option = $this->option('days');

        if ($option === null) {
            return (int) config('calm-tube.refresh.keep_runs_for_days');
        }

        return max((int) $option, 1);
    }
}
