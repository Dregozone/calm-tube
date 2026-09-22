# 02 — Architecture

## The stack that's already here

Everything below is installed; the design adds no Composer or npm dependencies.

| Layer | Choice | Version |
| --- | --- | --- |
| Runtime | PHP | 8.4 |
| Framework | Laravel | 13.33 |
| Database | SQLite (`database/database.sqlite`) | — |
| UI | Livewire single-file page components | 4.4 |
| Components | Flux UI Free | 2.20 |
| CSS | Tailwind | 4.3 |
| Build | Vite 8 via vite-plus (`npm run dev`) | — |
| Auth | Fortify (+ passkeys, 2FA) | 1.40 |
| HTTP | `Illuminate\Support\Facades\Http` (Guzzle 8) | — |
| Tests | Pest | 5.2 |
| Quality | Rector → Pint → PHPStan (`composer quality`) | — |

Cache, queue and session all use the `database` driver. The queue driver is configured but
**deliberately unused** — see [Refresh execution model](#refresh-execution-model).

## Layout

```
app/
  Console/Commands/
    RefreshChannelsCommand.php        calm:refresh {--channel=} {--force}
    EnrichVideosCommand.php           calm:enrich  (retries anything unenriched)
  Http/Controllers/
    ThumbnailController.php           streams archived video thumbnails
    AvatarController.php              streams archived channel avatars
  Jobs/
    RefreshChannel.php                one channel, end to end (dispatched sync)
  Models/
    Channel.php
    Video.php
    RefreshRun.php
  Services/YouTube/
    ChannelResolver.php               any input -> canonical channel ID + metadata
    RssFeedClient.php                 fetch + parse videos.xml
    DataApiClient.php                 videos.list / channels.list
    ShortsDetector.php                duration gate + /shorts/ probe
    ImageArchiver.php                 download + store thumbnails and avatars
    DurationParser.php                ISO 8601 -> seconds + human string
  Services/
    ChannelRefresher.php              orchestrates one refresh, returns a result object
  Support/
    RefreshResult.php                 value object: new/skipped/failed counts, error
config/
  calm-tube.php                       all app tuning in one file
resources/views/
  pages/⚡feed.blade.php
  pages/⚡watch.blade.php
  pages/channels/⚡index.blade.php
  pages/channels/⚡show.blade.php
  components/⚡video-card.blade.php    (Livewire: owns watched/hide actions)
  components/⚡refresh-button.blade.php
  components/channel-avatar.blade.php  (plain Blade)
routes/
  web.php                             feed, watch, channels, image routes
  console.php                         Schedule::command('calm:refresh')->hourly()
storage/app/calm-tube/
  thumbnails/{videoId}.jpg
  avatars/{channelId}.jpg
```

## Components

### `ChannelResolver`

Turns anything you can paste — `UC…` ID, `/channel/UC…` URL, `@handle`, bare handle,
`/user/legacy`, or even a video URL — into a canonical channel ID plus title and avatar URL.

Split into two halves so the hard part is trivially testable:

- **`parse(string $input): ResolvedInput`** — pure string/URL parsing, no network. Returns a
  type (`channel_id`, `handle`, `username`, `video_id`, `legacy_custom`) and a value.
- **`resolve(string $input): ChannelData`** — calls `DataApiClient` for the type identified.

Failure modes surface as typed exceptions (`UnresolvableChannelException`,
`ChannelNotFoundException`) that the UI maps to friendly messages. Full input matrix in
[05-youtube-integration.md](05-youtube-integration.md#channel-resolution).

### `RssFeedClient`

`GET https://www.youtube.com/feeds/videos.xml?channel_id={id}`, parsed with
`simplexml_load_string` (libxml is core PHP; no package needed). Returns an array of plain
`FeedEntry` value objects — video ID, title, description, published date, thumbnail URL.

Sends conditional request headers (`If-None-Match` / `If-Modified-Since`) built from values
stored on the channel, and treats a `304` as "nothing changed", skipping all downstream work.

### `DataApiClient`

Thin wrapper over the only two Data API v3 endpoints used:

- `videos.list` — batches of up to 50 IDs, `part=contentDetails,liveStreamingDetails,status`
- `channels.list` — `id` / `forHandle` / `forUsername`

Returns keyed arrays and knows nothing about our models. Throws `QuotaExceededException` on a
403 with reason `quotaExceeded`, and returns empty for missing IDs rather than throwing.
Reports `isConfigured()` as false when no API key is set, which every caller checks first.

### `ShortsDetector`

Decides `is_short` for one video, given its duration. A duration above the probe ceiling
(180s) short-circuits to `false` with no network call. Otherwise, one no-redirect `HEAD` to
`youtube.com/shorts/{id}`. Any failure falls back to the duration rule (`<= 60s`). Never
called twice for the same video: a non-null `is_short` is final.

### `ImageArchiver`

Downloads the best available thumbnail once and writes it to `storage/app/calm-tube/`. This
is what makes "titles and thumbnails never change" true for the image half; the title half is
simply never writing to that column again after insert.

Resolution order is `maxresdefault` → `mqdefault`; the source URL is stored alongside the
local path so a missing file falls back to hotlinking instead of a broken image.

### `ChannelRefresher`

The orchestrator. One method, `refresh(Channel $channel): RefreshResult`. It is the only place
that knows the *order* of operations, and the only place that writes to `videos`.

### `RefreshChannel` job

A thin `ShouldQueue` wrapper around `ChannelRefresher`, always invoked with `dispatchSync()`.
It exists so that moving to a real queue later is a one-word change.

## Refresh execution model

**Everything runs synchronously. There is no queue worker.**

Rationale: this is a local tool for one person. A queue would mean `php artisan queue:work`
has to be running or refreshes silently do nothing — the single most likely way for this app
to appear broken. With ~30 channels a full refresh is a few seconds of HTTP, which is a
perfectly reasonable thing to wait on behind a Livewire loading state.

The escape hatch: because the unit of work is a `ShouldQueue` job invoked via `dispatchSync()`,
switching to background refreshes later means changing `dispatchSync` to `dispatch` and
starting a worker. Nothing else moves.

Both entry points converge on the same job:

```
php artisan calm:refresh  ─┐
                           ├─> foreach enabled channel ─> RefreshChannel::dispatchSync()
"Refresh all" button ──────┘                                      │
                                                                  v
                                                          ChannelRefresher::refresh()
```

## Data flow: one channel refresh

```
                     ChannelRefresher::refresh(Channel)
                                  │
   1. open RefreshRun (status=running, started_at=now)
                                  │
   2. RssFeedClient::fetch(channelId, etag, lastModified)
                                  │
         ┌────────────────────────┼────────────────────────┐
       304                       200                    error/timeout
         │                        │                         │
    finish: 0 new         parse ~15 entries         log, RefreshRun=failed,
                                  │                 channel.last_refresh_error,
   3. diff against videos table   │                 return RefreshResult::failed()
      by youtube_video_id ────────┘
                                  │
      known IDs ──> skipped entirely (title/thumbnail are frozen; nothing is updated)
      new IDs   ──> continue
                                  │
   4. insert new rows: video id, title, description, published_at, thumbnail_url
      (is_short = null, duration_seconds = null, enriched_at = null)
                                  │
   5. DataApiClient::videos(ids)  ── no API key? skip to 7 (degraded)
      batched 50 per call, part=contentDetails,liveStreamingDetails,status
                                  │
      per video: duration_seconds, live_status, scheduled_start_at, enriched_at
      IDs absent from the response -> unavailable_at = now
      403 quotaExceeded            -> log, leave unenriched, mark run degraded
                                  │
   6. ShortsDetector::isShort(video)  for each new video
      duration > 180s -> false, no request made
      otherwise       -> HEAD youtube.com/shorts/{id}
                                  │
   7. ImageArchiver::archive(video) for each new, non-Short video
      (Shorts are never displayed, so their images are never fetched)
                                  │
   8. close RefreshRun (status, new_videos_count, finished_at)
      channel.last_refreshed_at = now, clear last_refresh_error
                                  │
                        return RefreshResult
```

Two things worth noticing:

- **Step 3 is where the archive guarantee lives.** A video already in the database is never
  touched by a refresh. Retitles and thumbnail swaps on YouTube cannot reach us, because we
  never look at an existing video's feed entry again.
- **Steps 5–7 only ever run for new videos**, which is why quota cost is negligible and why
  refreshing an idle channel costs one conditional request that usually returns 304.

## Error handling and degradation

Every failure is **isolated to one channel** and **never aborts a run**. `ChannelRefresher`
catches everything, records it, and returns a `RefreshResult`; the caller sums the results.

| Failure | Behaviour | What you see |
| --- | --- | --- |
| RSS timeout / 5xx / malformed XML | Retry once after 1s, then give up for this channel | Channel row: "Last refresh failed — couldn't reach the feed", with the time |
| RSS 404 (channel deleted or ID wrong) | Same, with a distinct message | "This channel's feed no longer exists", plus a prompt to re-add or disable it |
| No `YOUTUBE_API_KEY` | Skip enrichment; videos store with `duration_seconds = null` and `enriched_at = null`. Shorts detection probes every new video instead of gating on duration | One-line feed banner: "No API key — durations unavailable." Everything else works |
| API 403 `quotaExceeded` | Abort enrichment for the whole run, keep RSS data | Banner: "YouTube API quota reached; durations will fill in after it resets" |
| API 400 / invalid key | Same as no key, logged at `error` | Banner names the problem |
| Video ID missing from `videos.list` | `unavailable_at = now`; excluded from the feed | Nothing — it silently never appears |
| Shorts probe fails or is ambiguous | Fall back to `duration <= 60s` | Nothing; at worst a Short leaks into the feed |
| Thumbnail download fails | `thumbnail_path` stays null; the image route falls back to the stored YouTube URL, retried on the next refresh | Card looks normal |
| Embed refuses to play (error 101/150) | IFrame `onError` renders a panel | "This video can't be embedded — open it on YouTube" |

**Unenriched videos self-heal.** A nightly `calm:enrich` pass picks up every video with
`enriched_at IS NULL` and retries, so a temporary key or quota problem resolves itself without
you doing anything.

## Logging

A dedicated channel keeps refresh noise out of `laravel.log`:

```php
// config/logging.php
'calm' => [
    'driver' => 'daily',
    'path' => storage_path('logs/calm-tube.log'),
    'level' => 'debug',
    'days' => 14,
],
```

| Level | Used for |
| --- | --- |
| `debug` | Per-request URL + status, 304 hits, Shorts probe decisions |
| `info` | Refresh summary per channel: `{channel, new, skipped, duration_ms}` |
| `warning` | Degradations — missing key, quota, probe fallback, image failure |
| `error` | Channel refresh failure, unexpected exceptions (with the exception) |

Every line carries `channel_id` and, where relevant, `video_id`, so `php artisan pail` or a
`grep` for one channel tells the whole story. Refresh outcomes are also persisted to
`refresh_runs` so the UI can show history without parsing logs.

## Configuration

All tuning lives in `config/calm-tube.php`, with a minimal `.env` surface:

```php
return [
    'api_key' => env('YOUTUBE_API_KEY'),

    'refresh' => [
        'timeout' => 10,             // seconds, per HTTP request
        'retries' => 2,
        'retry_delay' => 1000,       // ms
    ],

    'shorts' => [
        'probe' => true,             // false = duration rule only
        'probe_max_seconds' => 180,  // above this it is never a Short; no request made
        'fallback_max_seconds' => 60, // used when the probe cannot answer
    ],

    'images' => [
        'preferred' => ['maxresdefault', 'mqdefault'],
        'disk' => 'local',
        'path' => 'calm-tube',
    ],

    'feed' => [
        'per_page' => 24,
    ],
];
```

`.env` gains exactly one key:

```
YOUTUBE_API_KEY=
```

The app must boot, migrate and run with that line absent or empty.
