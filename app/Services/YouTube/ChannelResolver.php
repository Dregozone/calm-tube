<?php

namespace App\Services\YouTube;

use App\Enums\ChannelInputType;
use App\Exceptions\ChannelNotFoundException;
use App\Exceptions\UnresolvableChannelException;
use App\Exceptions\UnsupportedChannelUrlException;
use App\Support\ChannelData;
use App\Support\ResolvedInput;
use Illuminate\Support\Str;

/**
 * Turns anything you can paste into a canonical channel.
 *
 * Parsing is deliberately separate from resolving, so the many input shapes
 * can be proven without touching the network.
 */
class ChannelResolver
{
    private const string CHANNEL_ID_PATTERN = '/^UC[A-Za-z0-9_-]{22}$/';

    private const string VIDEO_ID_PATTERN = '/^[A-Za-z0-9_-]{11}$/';

    private const array YOUTUBE_HOSTS = ['youtube.com', 'm.youtube.com', 'music.youtube.com'];

    public function __construct(
        private readonly DataApiClient $api,
        private readonly RssFeedClient $feeds,
    ) {}

    /**
     * @throws UnresolvableChannelException
     */
    public function parse(string $input): ResolvedInput
    {
        $trimmed = trim($input);

        if ($trimmed === '') {
            throw UnresolvableChannelException::cannotParse();
        }

        if (str_contains($trimmed, '/')) {
            return $this->parseUrl($trimmed);
        }

        if (str_starts_with($trimmed, '@')) {
            return $this->handle(ltrim($trimmed, '@'));
        }

        if (preg_match(self::CHANNEL_ID_PATTERN, $trimmed) === 1) {
            return new ResolvedInput(ChannelInputType::ChannelId, $trimmed);
        }

        // Anything else starting with UC is a channel id that has been
        // mistyped or truncated, rather than a handle.
        if (str_starts_with($trimmed, 'UC')) {
            throw UnresolvableChannelException::cannotParse();
        }

        if (preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $trimmed) === 1) {
            return $this->handle($trimmed);
        }

        throw UnresolvableChannelException::cannotParse();
    }

    /**
     * @throws UnresolvableChannelException
     * @throws UnsupportedChannelUrlException
     * @throws ChannelNotFoundException
     */
    public function resolve(string $input): ChannelData
    {
        $parsed = $this->parse($input);

        return match ($parsed->type) {
            ChannelInputType::ChannelId => $this->fromChannelId($parsed->value),
            ChannelInputType::Handle => $this->fromApi(
                fn (): ?ChannelData => $this->api->channelByHandle($parsed->value),
                $parsed->value,
            ),
            ChannelInputType::Username => $this->fromApi(
                fn (): ?ChannelData => $this->api->channelByUsername($parsed->value),
                $parsed->value,
            ),
            ChannelInputType::VideoId => $this->fromVideo($parsed->value),
            ChannelInputType::LegacyCustom => throw UnsupportedChannelUrlException::legacyCustomUrl(),
        };
    }

    /**
     * @throws UnresolvableChannelException
     */
    private function parseUrl(string $input): ResolvedInput
    {
        $url = str_contains($input, '://') ? $input : 'https://'.$input;
        $parts = parse_url($url);

        if ($parts === false) {
            throw UnresolvableChannelException::cannotParse();
        }

        $host = Str::of($parts['host'] ?? '')->lower()->after('www.')->toString();
        $segments = array_values(array_filter(explode('/', trim($parts['path'] ?? '', '/'))));

        if ($host === 'youtu.be') {
            return $this->video($segments[0] ?? '');
        }

        if (! in_array($host, self::YOUTUBE_HOSTS, true)) {
            throw UnresolvableChannelException::cannotParse();
        }

        parse_str($parts['query'] ?? '', $query);
        $first = $segments[0] ?? '';

        if (str_starts_with($first, '@')) {
            return $this->handle(ltrim($first, '@'));
        }

        return match ($first) {
            'channel' => $this->channelId($segments[1] ?? ''),
            'user' => $this->requireValue($segments[1] ?? '', ChannelInputType::Username),
            'c' => $this->requireValue($segments[1] ?? '', ChannelInputType::LegacyCustom),
            'shorts', 'embed', 'live' => $this->video($segments[1] ?? ''),
            'watch' => $this->video(is_string($query['v'] ?? null) ? $query['v'] : ''),
            default => throw UnresolvableChannelException::cannotParse(),
        };
    }

    private function handle(string $handle): ResolvedInput
    {
        return $this->requireValue('@'.$handle, ChannelInputType::Handle, minimum: 2);
    }

    /**
     * @throws UnresolvableChannelException
     */
    private function channelId(string $value): ResolvedInput
    {
        if (preg_match(self::CHANNEL_ID_PATTERN, $value) !== 1) {
            throw UnresolvableChannelException::cannotParse();
        }

        return new ResolvedInput(ChannelInputType::ChannelId, $value);
    }

    /**
     * @throws UnresolvableChannelException
     */
    private function video(string $value): ResolvedInput
    {
        if (preg_match(self::VIDEO_ID_PATTERN, $value) !== 1) {
            throw UnresolvableChannelException::cannotParse();
        }

        return new ResolvedInput(ChannelInputType::VideoId, $value);
    }

    /**
     * @throws UnresolvableChannelException
     */
    private function requireValue(string $value, ChannelInputType $type, int $minimum = 1): ResolvedInput
    {
        if (mb_strlen($value) < $minimum) {
            throw UnresolvableChannelException::cannotParse();
        }

        return new ResolvedInput($type, $value);
    }

    /**
     * A full channel id needs no API key: the feed itself names the channel.
     *
     * @throws ChannelNotFoundException
     */
    private function fromChannelId(string $channelId): ChannelData
    {
        if ($this->api->isConfigured()) {
            return $this->api->channelById($channelId)
                ?? throw ChannelNotFoundException::for($channelId);
        }

        $feed = $this->feeds->fetch($channelId);

        return new ChannelData(
            channelId: $channelId,
            title: $feed->channelTitle ?? $channelId,
        );
    }

    /**
     * @throws UnresolvableChannelException
     * @throws ChannelNotFoundException
     */
    private function fromVideo(string $videoId): ChannelData
    {
        $this->requireApiKey();

        $channelId = $this->api->channelIdForVideo($videoId)
            ?? throw ChannelNotFoundException::for($videoId);

        return $this->fromChannelId($channelId);
    }

    /**
     * @param  callable(): ?ChannelData  $lookup
     *
     * @throws UnresolvableChannelException
     * @throws ChannelNotFoundException
     */
    private function fromApi(callable $lookup, string $input): ChannelData
    {
        $this->requireApiKey();

        return $lookup() ?? throw ChannelNotFoundException::for($input);
    }

    /**
     * @throws UnresolvableChannelException
     */
    private function requireApiKey(): void
    {
        if (! $this->api->isConfigured()) {
            throw UnresolvableChannelException::needsApiKey();
        }
    }
}
