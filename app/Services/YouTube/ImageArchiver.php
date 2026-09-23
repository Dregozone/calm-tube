<?php

namespace App\Services\YouTube;

use App\Models\Channel;
use App\Models\Mix;
use App\Models\Video;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Downloads a thumbnail once and keeps it.
 *
 * This is the half of the archive guarantee that a database column cannot
 * provide: an uploader who swaps a thumbnail days later to chase the
 * algorithm changes what YouTube serves, not what you already have.
 *
 * The URL archived is whatever YouTube returned. Nothing is constructed.
 */
class ImageArchiver
{
    public function archiveThumbnail(Video $video): ?string
    {
        if ($video->thumbnail_path !== null) {
            return $video->thumbnail_path;
        }

        return $this->archive(
            $video->thumbnail_url,
            $this->path('thumbnails', $video->youtube_video_id),
        );
    }

    public function archiveMixThumbnail(Mix $mix): ?string
    {
        if ($mix->thumbnail_path !== null) {
            return $mix->thumbnail_path;
        }

        return $this->archive(
            $mix->thumbnail_url,
            $this->path('mixes', $mix->youtube_video_id),
        );
    }

    public function archiveAvatar(Channel $channel): ?string
    {
        if ($channel->avatar_path !== null) {
            return $channel->avatar_path;
        }

        return $this->archive(
            $channel->avatar_url,
            $this->path('avatars', $channel->youtube_channel_id),
        );
    }

    private function archive(?string $url, string $path): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        try {
            $response = Http::timeout((int) config('calm-tube.refresh.timeout'))->get($url);
        } catch (ConnectionException) {
            Log::channel('calm')->warning('Image download failed', ['url' => $url]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $body = $response->body();

        // A missing thumbnail is answered with a tiny grey placeholder rather
        // than a 404, so size is what tells them apart.
        if (strlen($body) < (int) config('calm-tube.images.minimum_bytes')) {
            Log::channel('calm')->warning('Image looked like a placeholder', [
                'url' => $url,
                'bytes' => strlen($body),
            ]);

            return null;
        }

        Storage::disk($this->disk())->put($path, $body);

        return $path;
    }

    private function path(string $folder, string $id): string
    {
        return sprintf('%s/%s/%s.jpg', config('calm-tube.images.path'), $folder, $id);
    }

    private function disk(): string
    {
        return (string) config('calm-tube.images.disk');
    }
}
