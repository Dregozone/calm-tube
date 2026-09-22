<?php

namespace App\Services\YouTube;

use App\Exceptions\FeedUnavailableException;
use App\Support\FeedEntry;
use App\Support\FeedResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Throwable;

/**
 * Reads a channel's public RSS feed, which is how new uploads are discovered.
 *
 * Free, unauthenticated and unmetered, so the API is only ever an enrichment
 * step on top of what this finds.
 */
class RssFeedClient
{
    private const string FEED_URL = 'https://www.youtube.com/feeds/videos.xml';

    private const string YT_NAMESPACE = 'http://www.youtube.com/xml/schemas/2015';

    private const string MEDIA_NAMESPACE = 'http://search.yahoo.com/mrss/';

    /**
     * Passing the stored validators turns an idle refresh into a 304 with an
     * empty body, so nothing downstream runs.
     *
     * @throws FeedUnavailableException
     */
    public function fetch(
        string $channelId,
        ?string $etag = null,
        ?string $lastModified = null,
    ): FeedResponse {
        $headers = array_filter([
            'If-None-Match' => $etag,
            'If-Modified-Since' => $lastModified,
        ]);

        try {
            $response = $this->request()
                ->withHeaders($headers)
                ->get(self::FEED_URL, ['channel_id' => $channelId]);
        } catch (ConnectionException) {
            throw FeedUnavailableException::unreachable($channelId);
        }

        if ($response->status() === 304) {
            return FeedResponse::notModified($etag, $lastModified);
        }

        if ($response->notFound()) {
            throw FeedUnavailableException::missing($channelId);
        }

        if (! $response->successful()) {
            throw FeedUnavailableException::unreachable($channelId);
        }

        return $this->parse($channelId, $response->body(), $response);
    }

    /**
     * @throws FeedUnavailableException
     */
    private function parse(string $channelId, string $body, mixed $response): FeedResponse
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($body);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        // YouTube sometimes answers with an HTML error page, which can itself
        // be well formed, so the root element is what decides.
        if (! $xml instanceof SimpleXMLElement || $xml->getName() !== 'feed') {
            throw FeedUnavailableException::unreadable($channelId);
        }

        $entries = [];

        foreach ($xml->entry as $entry) {
            $parsed = $this->toEntry($entry);

            if ($parsed instanceof FeedEntry) {
                $entries[] = $parsed;
            }
        }

        return new FeedResponse(
            entries: $entries,
            channelTitle: $this->text($xml->title),
            etag: $response->header('ETag') ?: null,
            lastModified: $response->header('Last-Modified') ?: null,
        );
    }

    private function toEntry(SimpleXMLElement $entry): ?FeedEntry
    {
        $videoId = $this->text($entry->children(self::YT_NAMESPACE)->videoId);
        $title = $this->text($entry->title);
        $published = $this->text($entry->published);

        if ($videoId === null || $title === null || $published === null) {
            return null;
        }

        $media = $entry->children(self::MEDIA_NAMESPACE)->group;

        return new FeedEntry(
            videoId: $videoId,
            title: $title,
            description: $media === null ? null : $this->text($media->description),
            publishedAt: CarbonImmutable::parse($published),
            thumbnailUrl: $media === null
                ? null
                : $this->text($media->thumbnail->attributes()?->{'url'}),
        );
    }

    private function request(): PendingRequest
    {
        return Http::retry(
            max((int) config('calm-tube.refresh.retries'), 1),
            (int) config('calm-tube.refresh.retry_delay'),
            fn (?Throwable $exception): bool => $this->shouldRetry($exception),
            throw: false,
        )->timeout((int) config('calm-tube.refresh.timeout'));
    }

    /**
     * A 304 reaches this callback with no exception, and must not be retried.
     */
    private function shouldRetry(?Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        return $exception instanceof RequestException
            && $exception->response->serverError();
    }

    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
