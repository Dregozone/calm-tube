{{--
    Columns follow the space the grid actually has, not the window, so
    collapsing the sidebar can add some. The counts stop at six: any more and the thumbnails are too
    small to read at a glance. Breakpoints are in rem, not px: Tailwind cannot
    order the two against each other, and a px rule emitted before @4xl loses
    to it.
--}}
<div {{ $attributes->class('@container') }}>
    <div class="grid grid-cols-1 gap-x-4 gap-y-5 @xl:grid-cols-2 @4xl:grid-cols-3 @min-[76rem]:grid-cols-4 @min-[112rem]:grid-cols-6">
        {{ $slot }}
    </div>
</div>
