{{-- A play mark resting above a still horizon: video, without the noise.
     Carries its own colours, so it reads the same in light and dark. --}}
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" {{ $attributes }}>
    <defs>
        <linearGradient id="calm-tube-logo-bg" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stop-color="#2dd4bf" />
            <stop offset="1" stop-color="#0e7490" />
        </linearGradient>
    </defs>
    <rect width="64" height="64" rx="15" fill="url(#calm-tube-logo-bg)" />
    <path d="M25 18.5v21l17-10.5z" fill="#fff" stroke="#fff" stroke-width="6" stroke-linejoin="round" />
    <path d="M17 50h30" stroke="#fff" stroke-opacity=".6" stroke-width="3.5" stroke-linecap="round" />
</svg>
