{{-- One course on the courses page. Courses are usually ordinary websites,
     so nothing is embedded: the link opens it in a new tab. --}}
<flux:card size="sm" class="flex flex-col gap-3" wire:key="course-{{ $course->id }}" data-reading-list-course="{{ $course->reading_list_id }}">
    <div>
        <flux:heading class="leading-snug">{{ $course->title }}</flux:heading>

        @if ($course->duration_for_humans)
            <flux:text size="sm" class="mt-1 tabular-nums">{{ $course->duration_for_humans }}</flux:text>
        @endif
    </div>

    <div class="flex items-center gap-3">
        <flux:progress :value="$course->percent_complete" class="flex-1" aria-label="{{ __('Progress') }}" />
        <flux:text size="sm" class="tabular-nums">{{ $course->percent_complete }}%</flux:text>
    </div>

    @if ($course->notes)
        <flux:text size="sm" class="whitespace-pre-line">{{ $course->notes }}</flux:text>
    @endif

    @if ($course->url)
        <div class="mt-auto">
            <flux:button
                :href="$course->url"
                target="_blank"
                rel="noopener"
                size="sm"
                variant="subtle"
                icon-trailing="arrow-top-right-on-square"
            >
                {{ __('Open course') }}
            </flux:button>
        </div>
    @endif
</flux:card>
