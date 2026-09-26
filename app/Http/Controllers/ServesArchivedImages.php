<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serving an archived image, with somewhere to fall back to.
 *
 * An archived image never changes, so the browser is told never to ask again.
 * When the file is missing the original URL still works, so a failed download
 * shows the right picture rather than a broken one, and the next refresh
 * retries the archive.
 */
trait ServesArchivedImages
{
    private function serve(?string $path, ?string $fallbackUrl): Response|RedirectResponse
    {
        $disk = Storage::disk((string) config('calm-tube.images.disk'));

        $contents = $path !== null ? $disk->get($path) : null;

        if ($contents !== null) {
            return response($contents, 200, [
                'Content-Type' => 'image/jpeg',
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ]);
        }

        if ($fallbackUrl !== null && $fallbackUrl !== '') {
            return redirect()->away($fallbackUrl);
        }

        return response()->file(public_path('images/thumbnail-placeholder.svg'), [
            'Content-Type' => 'image/svg+xml',
        ]);
    }
}
