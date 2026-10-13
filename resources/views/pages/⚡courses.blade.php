<?php

use App\Livewire\ReadingListPage;
use App\Models\ReadingListCourse;
use App\Services\ReadingList\ReadingListSync;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;

new #[Title('Courses')] class extends ReadingListPage
{
    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $sync = app(ReadingListSync::class);
        [$finished, $courses] = $this->courses()->partition(fn (ReadingListCourse $course): bool => $course->isFinished());

        return [
            'courses' => $courses->values(),
            'finished' => $finished->values(),
            'configured' => $sync->isConfigured(),
            'lastRefreshedAt' => $sync->lastRefreshedAt(),
            'stale' => $sync->isConfigured() && $sync->isStale(),
        ];
    }

    /**
     * Whatever you have started first, then Personal Assistant's priority
     * (important × urgent), then the list's own order.
     *
     * @return Collection<int, ReadingListCourse>
     */
    private function courses(): Collection
    {
        return ReadingListCourse::query()
            ->orderBy('position')
            ->get()
            ->sortBy([
                fn (ReadingListCourse $a, ReadingListCourse $b): int => $b->isInProgress() <=> $a->isInProgress(),
                fn (ReadingListCourse $a, ReadingListCourse $b): int => $b->priority <=> $a->priority,
                fn (ReadingListCourse $a, ReadingListCourse $b): int => $a->position <=> $b->position,
            ])
            ->values();
    }
}; ?>

<section class="w-full" @if ($stale) wire:init="autoRefresh" @endif>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Courses') }}</flux:heading>

            <flux:text class="mt-1">
                {{ __('The courses on your reading list, for when you have time to spare.') }}
            </flux:text>
        </div>

        @if ($configured)
            <div class="flex flex-wrap items-center gap-3">
                @if ($lastRefreshedAt)
                    <flux:text size="sm" wire:loading.remove wire:target="refresh, autoRefresh">
                        {{ __('Last updated') }} {{ $lastRefreshedAt->diffForHumans() }}@if ($error) ({{ __("couldn't reach Personal Assistant") }})@endif
                    </flux:text>
                @endif

                <flux:text size="sm" wire:loading wire:target="refresh, autoRefresh">
                    {{ __('Checking your reading list') }}…
                </flux:text>

                <flux:button
                    wire:click="refresh"
                    icon="arrow-path"
                    variant="subtle"
                    wire:loading.attr="disabled"
                    class="data-loading:opacity-50"
                >
                    {{ __('Refresh') }}
                </flux:button>
            </div>
        @endif
    </div>

    @if ($status)
        <flux:callout variant="secondary" class="mt-4">{{ $status }}</flux:callout>
    @endif

    {{-- With a copy to show, the line beside Refresh already says so. --}}
    @if ($error && ! $lastRefreshedAt)
        <flux:callout variant="warning" class="mt-4">{{ $error }}</flux:callout>
    @endif

    @unless ($configured)
        <flux:callout variant="warning" class="mt-4">
            {{ __('Personal Assistant is not set up. Add READING_LIST_URL, READING_LIST_IDENTIFIER and READING_LIST_TOKEN to your .env.') }}
        </flux:callout>
    @endunless

    @if ($courses->isEmpty() && $finished->isEmpty())
        <div class="mt-16 text-center">
            <flux:heading size="lg">{{ __('No courses in your reading list.') }}</flux:heading>

            <flux:text class="mt-2">
                {{ __('Reading list items in the Course category in Personal Assistant show up here.') }}
            </flux:text>
        </div>
    @else
        @if ($courses->isNotEmpty())
            <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($courses as $course)
                    @include('partials.course-card', ['course' => $course])
                @endforeach
            </div>
        @endif

        @if ($finished->isNotEmpty())
            <details class="group mt-8" data-finished-courses>
                <summary class="flex cursor-pointer list-none items-center gap-2 select-none">
                    <flux:icon.chevron-right variant="micro" class="text-zinc-400 transition-transform group-open:rotate-90" />
                    <flux:heading>{{ __('Finished') }}</flux:heading>
                    <flux:badge size="sm">{{ $finished->count() }}</flux:badge>
                </summary>

                <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($finished as $course)
                        @include('partials.course-card', ['course' => $course])
                    @endforeach
                </div>
            </details>
        @endif
    @endif
</section>
