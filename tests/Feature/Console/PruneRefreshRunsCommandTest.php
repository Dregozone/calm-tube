<?php

use App\Models\Channel;
use App\Models\RefreshRun;
use App\Models\Video;
use Illuminate\Support\Facades\Schedule;

it('removes refresh history older than the retention window', function (): void {
    RefreshRun::factory()->create(['started_at' => now()->subDays(45)]);
    $kept = RefreshRun::factory()->create(['started_at' => now()->subDays(10)]);

    $this->artisan('calm:prune-runs')->assertSuccessful();

    expect(RefreshRun::query()->pluck('id')->all())->toBe([$kept->id]);
});

it('keeps a run that is exactly at the edge of the window', function (): void {
    // Thirty days old is inside thirty days; only older than that goes.
    RefreshRun::factory()->create(['started_at' => now()->subDays(30)->addMinute()]);

    $this->artisan('calm:prune-runs')->assertSuccessful();

    expect(RefreshRun::query()->count())->toBe(1);
});

it('honours a retention window passed on the command line', function (): void {
    RefreshRun::factory()->create(['started_at' => now()->subDays(10)]);

    $this->artisan('calm:prune-runs', ['--days' => 7])->assertSuccessful();

    expect(RefreshRun::query()->count())->toBe(0);
});

it('honours the configured retention window', function (): void {
    config()->set('calm-tube.refresh.keep_runs_for_days', 3);
    RefreshRun::factory()->create(['started_at' => now()->subDays(5)]);

    $this->artisan('calm:prune-runs')->assertSuccessful();

    expect(RefreshRun::query()->count())->toBe(0);
});

it('says so when there is nothing to prune', function (): void {
    RefreshRun::factory()->create(['started_at' => now()->subDay()]);

    $this->artisan('calm:prune-runs')
        ->expectsOutputToContain('Nothing to prune')
        ->assertSuccessful();
});

it('reports how many it removed', function (): void {
    RefreshRun::factory()->count(3)->create(['started_at' => now()->subDays(60)]);

    $this->artisan('calm:prune-runs')->expectsOutputToContain('Pruned 3 refresh runs');
});

it('never touches the archive itself', function (): void {
    $channel = Channel::factory()->create();
    $video = Video::factory()->for($channel)->create();
    RefreshRun::factory()->for($channel)->create(['started_at' => now()->subDays(60)]);

    $this->artisan('calm:prune-runs')->assertSuccessful();

    $this->assertModelExists($channel);
    $this->assertModelExists($video);
    expect(RefreshRun::query()->count())->toBe(0);
});

it('is scheduled weekly', function (): void {
    $events = collect(Schedule::events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'calm:prune-runs'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 5 * * 1');
});
