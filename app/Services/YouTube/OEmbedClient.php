<?php

namespace App\Services\YouTube;

use App\Exceptions\VideoLookupException;
use App\Support\EmbedData;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Looks up one video through YouTube's public oEmbed endpoint.
 *
 * Free, unmetered and keyless, so a mix can be added whether or not there is
 * an API key. It also answers the one question that matters for a mix: a
 * video YouTube will not let other sites embed comes back as an error here,
 * before it is ever added to a list it could not play from.
 */
class OEmbedClient
{
    private const string ENDPOINT = 'https://www.youtube.com/oembed';

    /**
     * @throws VideoLookupException
     */
    public function lookup(string $videoId): EmbedData
    {
        try {
            $response = $this->request()->get(self::ENDPOINT, [
                'url' => 'https://www.youtube.com/watch?v='.$videoId,
                'format' => 'json',
            ]);
        } catch (ConnectionException) {
            throw VideoLookupException::unreachable($videoId);
        }

        if ($response->serverError()) {
            throw VideoLookupException::unreachable($videoId);
        }

        $title = $response->json('title');

        // 400 for an id that is not one, 401 for private or not embeddable,
        // 404 for gone.
        if (! $response->successful() || ! is_string($title) || $title === '') {
            throw VideoLookupException::notPlayable($videoId);
        }

        return new EmbedData(
            videoId: $videoId,
            title: $title,
            authorName: $this->text($response->json('author_name')),
            thumbnailUrl: $this->text($response->json('thumbnail_url')),
        );
    }

    private function request(): PendingRequest
    {
        return Http::retry(
            max((int) config('calm-tube.refresh.retries'), 1),
            (int) config('calm-tube.refresh.retry_delay'),
            fn (?Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && $exception->response->serverError()),
            throw: false,
        )->timeout((int) config('calm-tube.refresh.timeout'));
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
