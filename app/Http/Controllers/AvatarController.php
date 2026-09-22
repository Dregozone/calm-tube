<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a channel's archived avatar.
 */
class AvatarController extends Controller
{
    use ServesArchivedImages;

    public function __invoke(Channel $channel): Response|RedirectResponse
    {
        return $this->serve($channel->avatar_path, $channel->avatar_url);
    }
}
