<?php

namespace App\Services\ReadingList;

use Illuminate\Support\Str;

/**
 * Picks the YouTube videos out of a reading list, which is mostly articles.
 *
 * Watch pages, youtu.be links and /live links count. Shorts never do, which
 * is Calm Tube's rule rather than the list's, and neither does anything else
 * on YouTube: a channel or a playlist is not a video to watch.
 */
class VideoUrlParser
{
    private const array YOUTUBE_HOSTS = ['youtube.com', 'm.youtube.com', 'music.youtube.com'];

    private const string VIDEO_ID_PATTERN = '/^[A-Za-z0-9_-]{11}$/';

    public function videoId(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $trimmed = trim($url);
        $parts = parse_url(str_contains($trimmed, '://') ? $trimmed : 'https://'.$trimmed);

        if ($parts === false) {
            return null;
        }

        $host = Str::of($parts['host'] ?? '')->lower()->after('www.')->toString();
        $segments = array_values(array_filter(explode('/', trim($parts['path'] ?? '', '/'))));

        if ($host === 'youtu.be') {
            return $this->valid($segments[0] ?? null);
        }

        if (! in_array($host, self::YOUTUBE_HOSTS, true)) {
            return null;
        }

        parse_str($parts['query'] ?? '', $query);

        return match ($segments[0] ?? '') {
            'watch' => $this->valid(is_string($query['v'] ?? null) ? $query['v'] : null),
            'live' => $this->valid($segments[1] ?? null),
            default => null,
        };
    }

    private function valid(?string $id): ?string
    {
        return $id !== null && preg_match(self::VIDEO_ID_PATTERN, $id) === 1 ? $id : null;
    }
}
