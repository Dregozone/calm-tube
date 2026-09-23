<?php

namespace App\Http\Controllers;

use App\Models\Mix;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a mix's archived thumbnail, the same way a video's is served.
 */
class MixThumbnailController extends Controller
{
    use ServesArchivedImages;

    public function __invoke(Mix $mix): Response|RedirectResponse
    {
        return $this->serve($mix->thumbnail_path, $mix->thumbnail_url);
    }
}
