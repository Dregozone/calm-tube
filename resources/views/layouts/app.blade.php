{{-- A flush page (the watch page) sets its own tighter padding, because the
     player wants every pixel of height the window has. --}}
<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main @class(['p-0! lg:p-0!' => $flush ?? false])>
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
