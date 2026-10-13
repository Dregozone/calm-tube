<?php

namespace App\Services\ReadingList;

use App\Exceptions\ReadingListException;
use App\Support\ReadingListEntry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The only thing that talks to Personal Assistant.
 *
 * Uses its reading list API exactly as it is: the list in one GET, and
 * progress written back one item at a time. Personal Assistant accepts 0 to
 * 99 percent; completing an item is something only it does.
 */
class ReadingListClient
{
    public function isConfigured(): bool
    {
        return $this->baseUrl() !== null
            && $this->text(config('services.reading_list.identifier')) !== null
            && $this->text(config('services.reading_list.token')) !== null;
    }

    /**
     * Every item on the list, in Personal Assistant's order.
     *
     * @return list<ReadingListEntry>
     *
     * @throws ReadingListException
     */
    public function items(): array
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->get($this->endpoint()));

        $payload = $response->json();
        $entries = [];

        foreach (is_array($payload) ? $payload : [] as $item) {
            $entry = $this->toEntry($item);

            if ($entry instanceof ReadingListEntry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @throws ReadingListException
     */
    public function setProgress(int $itemId, int $percent): void
    {
        $this->send(fn (PendingRequest $request): Response => $request->patch(
            $this->endpoint().'/'.$itemId,
            ['percent_complete' => max(0, min(99, $percent))],
        ));
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     *
     * @throws ReadingListException
     */
    private function send(callable $call): Response
    {
        if (! $this->isConfigured()) {
            throw ReadingListException::notConfigured();
        }

        try {
            $response = $call($this->request());
        } catch (ConnectionException) {
            throw ReadingListException::unreachable();
        }

        if ($response->failed()) {
            throw ReadingListException::status($response->status());
        }

        return $response;
    }

    private function toEntry(mixed $item): ?ReadingListEntry
    {
        $id = data_get($item, 'id');
        $title = $this->text(data_get($item, 'title'));

        if (! is_int($id) || $title === null) {
            return null;
        }

        $duration = $this->number(data_get($item, 'estimated_duration'));

        return new ReadingListEntry(
            id: $id,
            title: $title,
            url: $this->text(data_get($item, 'url')),
            category: $this->text(data_get($item, 'category')),
            percentComplete: $this->number(data_get($item, 'percent_complete')) ?? 0,
            notes: $this->text(data_get($item, 'notes')),
            important: $this->number(data_get($item, 'important')) ?? 0,
            urgent: $this->number(data_get($item, 'urgent')) ?? 0,
            estimatedDuration: $duration !== null && $duration > 0 ? $duration : null,
        );
    }

    private function number(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * The identifier and token are path segments, which is how Personal
     * Assistant's API takes them.
     */
    private function endpoint(): string
    {
        return $this->baseUrl().'/api/readingList/'
            .rawurlencode((string) config('services.reading_list.identifier')).'/'
            .rawurlencode((string) config('services.reading_list.token'));
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->retry(
                max((int) config('calm-tube.refresh.retries'), 1),
                (int) config('calm-tube.refresh.retry_delay'),
                fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()),
                throw: false,
            )
            ->timeout((int) config('calm-tube.refresh.timeout'));
    }

    private function baseUrl(): ?string
    {
        $url = $this->text(config('services.reading_list.url'));

        return $url === null ? null : rtrim($url, '/');
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
