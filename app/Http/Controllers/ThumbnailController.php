<?php

namespace App\Http\Controllers;

use App\Models\Video;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a video's archived thumbnail.
 *
 * A route rather than a public disk symlink: storage:link needs Developer Mode
 * or an admin shell on Windows and fails confusingly when it does not have
 * them. A route sidesteps that, and gives somewhere to fall back from.
 */
class ThumbnailController extends Controller
{
    use ServesArchivedImages;

    public function __invoke(Video $video): Response|RedirectResponse
    {
        return $this->serve($video->thumbnail_path, $video->thumbnail_url);
    }
}
