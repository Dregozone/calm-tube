# 06 — Implementation Plan

Ten phases, each independently shippable and independently testable. Every phase ends with a
working app — never a half-migrated state.

**Per-phase discipline**

1. Generate files with `php artisan make:… --no-interaction`.
2. Write the test alongside the code, not after the phase.
3. `php artisan test --compact --filter=…` for the narrowest relevant set.
4. `composer quality` (Rector → Pint → PHPStan) before considering the phase done.
5. Commit per phase.

---

## Phase 0 — Groundwork

Strip the starter kit's placeholders and lay the foundations. No YouTube code yet.

**Tasks**

- `config/calm-tube.php` with the structure from
  [02-architecture.md](02-architecture.md#configuration); add `YOUTUBE_API_KEY=` to `.env`
  and `.env.example`.
- Add the `calm` log channel to `config/logging.php`.
- Remove the dashboard route, view and test; point `/` at `/feed` (a placeholder view for now).
- Reduce the Flux sidebar to **Feed** and **Channels**.
- Set `APP_NAME="Calm Tube"`.

**Acceptance**

- `php artisan config:show calm-tube` prints the full config with no API key set.
- `php artisan test` passes (dashboard test removed, auth tests untouched).
- Logging in lands on `/feed`; the nav shows two items.

---

## Phase 1 — Data model

**Tasks**

- Migrations for `channels`, `videos`, `refresh_runs` per
  [03-data-model.md](03-data-model.md).
- `Channel`, `Video`, `RefreshRun` models with casts, relationships, `getRouteKeyName()`.
- `LiveStatus` enum (`None`, `Live`, `Upcoming`).
- `Video::scopeInFeed()`, `scopeUnwatched()`; `Channel::scopeEnabled()`.
- `Channel::displayName()` accessor; `Video::durationForHumans()` accessor.
- Factories with all the states listed in the data model doc.

**Tests** — `tests/Feature/Models/VideoScopesTest.php`

- `inFeed` excludes: hidden, unavailable, Shorts, live, upcoming, and videos on disabled
  channels.
- `inFeed` **includes** a video with `is_short = null` (the unknown-is-shown rule).
- `display_name` prefers `custom_name`, falls back to `title`.
- Deleting a channel cascades to its videos and refresh runs.

**Acceptance**

- `php artisan migrate:fresh` succeeds on SQLite.
- Every scope has a test asserting both the included and the excluded case.

---

## Phase 2 — Duration parsing and the API client

Pure logic and HTTP plumbing, ahead of any UI.

**Tasks**

- `DurationParser` (`toSeconds`, `toHuman`).
- `DataApiClient` with `videos(array $ids)`, `channelById/ByHandle/ByUsername`,
  `isConfigured()`.
- 50-ID chunking; `QuotaExceededException`; timeout and retry from config.
- Fixtures: `tests/Fixtures/youtube/videos.list.json`, `channels.list.json`,
  `quota-exceeded.json`, `empty-items.json`.

**Tests**

- `tests/Unit/DurationParserTest.php` — the full edge-case table from
  [05-youtube-integration.md](05-youtube-integration.md#duration-parsing), as a dataset.
- `tests/Feature/YouTube/DataApiClientTest.php`
  - 120 IDs produce exactly 3 requests (`Http::assertSentCount(3)`).
  - `part` never contains `snippet` for `videos.list` — the archive guard, asserted directly.
  - A 403 `quotaExceeded` body throws `QuotaExceededException`.
  - `isConfigured()` is false with an empty key, and no request is attempted.

**Acceptance**

- Zero real network calls: `Http::preventStrayRequests()` in `tests/Pest.php`.

---

## Phase 3 — Channel resolution and adding channels

First user-visible feature.

**Tasks**

- `ChannelResolver::parse()` — pure, covering the whole input matrix.
- `ChannelResolver::resolve()` — dispatches to `DataApiClient`.
- `UnresolvableChannelException`, `ChannelNotFoundException`.
- `pages::channels.index` with the add form and a bare list.
- No-API-key mode: `channel_id` inputs resolve their title from the RSS feed.

**Tests**

- `tests/Unit/ChannelResolverParseTest.php` — a dataset of every input form → expected type
  and value, plus the rejection cases. No HTTP.
- `tests/Feature/Channels/AddChannelTest.php`
  - Adding by handle creates the channel with the canonical ID.
  - Adding a duplicate shows the "you already follow" error and creates nothing.
  - Adding a duplicate that is disabled offers to enable it.
  - A `/c/` URL produces the specific guidance message.
  - Empty `items` produces "no channel found".
  - No API key + a handle produces the API-key message; no API key + a `UC…` ID succeeds.

**Acceptance**

- Every error row in [04-routes-and-ui.md](04-routes-and-ui.md#add-form-errors) has a test.
- A channel can be added end to end against faked HTTP.

---

## Phase 4 — RSS ingest and the refresh pipeline

The heart of the app.

**Tasks**

- `RssFeedClient` — fetch, conditional headers, parse, `FeedEntry` value objects.
- `ChannelRefresher::refresh()` — steps 1–4 and 8 of the data flow (no enrichment yet).
- `RefreshChannel` job, dispatched with `dispatchSync()`.
- `RefreshResult` value object.
- `calm:refresh {--channel=}` command.
- `Schedule::command('calm:refresh')->hourly()->withoutOverlapping()` in `routes/console.php`.
- Per-channel error isolation, `last_refresh_error`, `refresh_runs` rows.

**Tests** — `tests/Feature/Refresh/RefreshChannelTest.php`

- A 15-entry feed creates 15 videos with the right title, description and published date.
- Refreshing twice creates nothing new (idempotence).
- **A feed whose title and thumbnail URL have changed does not alter the stored row** — the
  archive guarantee, and the single most important test in the suite.
- A `304` creates nothing and makes no further requests.
- ETag and Last-Modified are sent when stored, and updated from the response.
- A timeout on channel 2 of 3 still refreshes channels 1 and 3; channel 2 records the error.
- Malformed XML is handled as a feed error, not an exception.
- `refresh_runs` records `new_videos_count` accurately.

**Acceptance**

- `php artisan calm:refresh` populates the database from fixtures in tests and from the real
  feed when run by hand.
- A failing channel never aborts the run.

---

## Phase 5 — Enrichment, live status and Shorts

**Tasks**

- Extend `ChannelRefresher` with steps 5–6.
- Live status inference from `liveStreamingDetails`.
- `unavailable_at` for IDs missing from the response.
- `ShortsDetector` with the duration gate, probe and fallback.
- `calm:enrich` command for `enriched_at IS NULL`, `live` and `upcoming` rows; scheduled daily.

**Tests**

- `tests/Feature/YouTube/ShortsDetectorTest.php` — the full decision table as a dataset,
  including "no request is made above the ceiling" (`Http::assertNothingSent()`) and both
  fallback directions on a connection failure.
- `tests/Feature/Refresh/EnrichmentTest.php`
  - Durations land on the right videos across a 50+ batch.
  - `liveStreamingDetails` combinations map to the right `live_status`.
  - A missing ID sets `unavailable_at`.
  - Quota exceeded leaves videos unenriched, marks the run `degraded`, and stores the RSS data.
  - With no API key, ingest still succeeds and durations are null.
  - `calm:enrich` promotes a finished stream from `live` to `none` and fills its duration.

**Acceptance**

- A Short never appears in `Video::inFeed()`.
- Everything in this phase degrades to a working app when the API is unavailable.

---

## Phase 6 — Image archiving

**Tasks**

- `ImageArchiver` with the `maxresdefault` → `mqdefault` order and the placeholder-size check.
- Wire into the refresh (non-Shorts only).
- `ThumbnailController`, `AvatarController` and their routes.
- Delete files when a video or channel is deleted (model `deleting` event).
- Retry missing archives on subsequent refreshes.

**Tests** — `tests/Feature/Images/ImageArchiverTest.php` with `Storage::fake()`

- `maxresdefault` success stores that file; a 404 falls back to `mqdefault`.
- A sub-1 KB placeholder response is rejected and the fallback is used.
- A total failure leaves `thumbnail_path` null and the route redirects to `thumbnail_url`.
- The route sends `Cache-Control: …immutable` for a stored file.
- Deleting a channel removes its videos' image files.
- Shorts are never archived (`Http::assertNotSent()` for their IDs).

**Acceptance**

- With the network disconnected after a refresh, thumbnails still render from disk.

---

## Phase 7 — The feed

**Tasks**

- `pages::feed` with `WithPagination`, `#[Url]` filter properties, `per_page` from config.
- `video-card` (Livewire) — watched toggle, hide.
- `refresh-button` with loading state and result toast.
- All the empty, loading, error and degraded states from
  [04-routes-and-ui.md](04-routes-and-ui.md#feed-states).
- Eager-load `channel` on the paginated query.

**Tests** — `tests/Feature/Feed/FeedTest.php` (`Livewire::test('pages::feed')`)

- Newest first; page 2 continues correctly.
- Hidden, Short, live, upcoming, unavailable and disabled-channel videos are all absent.
- The unwatched filter narrows the set; the channel filter scopes it.
- Changing a filter resets to page 1.
- `toggleWatched` sets and clears `watched_at`.
- `hide` removes the video from the next render.
- `refreshAll` dispatches one job per **enabled** channel and reports the count.
- The no-API-key banner renders only when the key is absent.
- **No N+1**: assert a fixed query count for a 24-card page.

**Acceptance**

- 24 cards, working Previous/Next, and every empty state reachable.

---

## Phase 8 — The watch page

**Tasks**

- `pages::watch` with route model binding on `youtube_video_id`.
- Embed markup and parameters exactly as specified.
- Alpine + IFrame API inside `@script`; `ENDED` → overlay + `$wire.markWatched()`.
- Description escaping and linkification; "Show more" collapse.
- "Open on YouTube", watched toggle, "Next unwatched from this channel".
- `onError` handling, including `markUnavailable()` for error 100.

**Tests** — `tests/Feature/Watch/WatchPageTest.php`

- The page shows the **archived** title, not any newer one.
- The embed src is `youtube-nocookie.com` and contains `rel=0`, `enablejsapi=1`, `autoplay=0`.
- `markWatched` sets `watched_at`; the button flips to "Mark as unwatched".
- "Next unwatched" links to the right video, and is absent when there is none.
- A description containing `<script>` is escaped; a bare URL becomes a link with
  `rel="noopener noreferrer"`.
- An unavailable video still renders its archived metadata.
- Deep-linking an upcoming video renders without errors.

The JS itself is verified by hand — automated browser testing isn't worth it for one page.
Manual checklist: playing to the end shows the panel and marks watched; replay hides it; an
embed-disabled video shows the fallback.

**Acceptance**

- A full watch → end → "Back to feed" cycle works, and the video is watched on return.

---

## Phase 9 — Channel management

**Tasks**

- Finish `channels.index`: enabled toggle, per-channel refresh, counts, last-refreshed and
  error lines.
- Edit modal (`custom_name`, enabled).
- Delete confirmation with real counts and the "disable instead" path.
- `pages::channels.show` with the filtered, paginated grid.

**Tests** — `tests/Feature/Channels/ManageChannelsTest.php`

- `toggleEnabled` removes the channel's videos from the feed but keeps the rows.
- A custom name displays everywhere; clearing it reverts to the YouTube title.
- The delete confirmation reports the exact video and watched counts.
- Deleting removes videos, refresh runs and image files.
- `refreshChannel` refreshes only that channel.
- A disabled channel's page still renders its archive.

**Acceptance**

- Full CRUD, with disable as the obvious default and delete clearly explained.

---

## Phase 10 — Polish

**Tasks**

- Keyboard shortcuts (`/`, `g f`, `g c`).
- `calm:prune-runs` for `refresh_runs` older than 30 days; scheduled weekly.
- Optional: the `pointer-events: none` end-card mask for the final 20 seconds.
- Empty-state copy pass; dark mode check on every screen; mobile widths.
- A `README` section on running it: `composer run dev`, and `php artisan schedule:work` in a
  second terminal if you want unattended refreshes.
- Full `composer quality` and `php artisan test --compact` pass.

**Acceptance**

- Nothing in the UI suggests another video except the one explicit "next unwatched" button.

---

## Testing strategy

### Shape of the suite

| Kind | Where | What |
| --- | --- | --- |
| Unit (no framework) | `tests/Unit/` | `DurationParser`, `ChannelResolver::parse()`, `RefreshResult` |
| Feature — services | `tests/Feature/YouTube/`, `Refresh/`, `Images/` | HTTP clients and the refresh pipeline against faked responses |
| Feature — Livewire | `tests/Feature/Feed/`, `Watch/`, `Channels/` | Component behaviour via `Livewire::test()` |
| Manual | checklist in Phase 8 | The IFrame API end-of-video behaviour |

Feature tests dominate, per the project's Pest guidance. The two genuinely pure pieces —
duration parsing and input parsing — are unit tested because they have dozens of cases each
and no dependencies.

### Faking YouTube

Every external call goes through `Illuminate\Support\Facades\Http`, so `Http::fake()` covers
all of it. Put this in `tests/Pest.php` so a forgotten fake fails loudly instead of hitting
the network:

```php
uses(TestCase::class, RefreshDatabase::class)->in('Feature');

beforeEach(function (): void {
    Http::preventStrayRequests();
});
```

Fixtures live in `tests/Fixtures/youtube/` as real captured responses, loaded by a helper:

```php
// tests/Pest.php
function youtubeFixture(string $name): string
{
    return file_get_contents(__DIR__."/Fixtures/youtube/{$name}");
}

function fakeYouTube(array $overrides = []): void
{
    Http::fake(array_merge([
        'youtube.com/feeds/videos.xml*' => Http::response(youtubeFixture('feed.xml'), 200, [
            'ETag' => '"abc123"',
        ]),
        'googleapis.com/youtube/v3/videos*' => Http::response(youtubeFixture('videos.list.json')),
        'googleapis.com/youtube/v3/channels*' => Http::response(youtubeFixture('channels.list.json')),
        'youtube.com/shorts/*' => Http::response('', 303, ['Location' => 'https://www.youtube.com/watch?v=x']),
        'i.ytimg.com/*' => Http::response(youtubeFixture('thumb.jpg'), 200, ['Content-Type' => 'image/jpeg']),
    ], $overrides));
}
```

`fakeYouTube()` gives every test a working world; each test overrides only the one endpoint it
cares about:

```php
it('records an error when the feed times out', function (): void {
    fakeYouTube(['youtube.com/feeds/videos.xml*' => Http::failedConnection()]);
    // ...
});
```

Fixtures to capture once, by hand, and commit:

```
feed.xml                 a real 15-entry feed
feed-single-entry.xml    for precise assertions
feed-retitled.xml        same IDs, different titles + thumbnails — the archive test
feed-malformed.xml       an HTML error page
videos.list.json         mixed durations, one live, one upcoming, one missing ID
channels.list.json       by id / by handle
quota-exceeded.json      the real 403 body
thumb.jpg                a few KB
```

### Things worth asserting that are easy to forget

- **`Http::assertSentCount()`** everywhere batching or gating matters — the 50-ID chunking and
  "no probe above the ceiling" rules are both invisible unless counted.
- **`Http::assertNotSent()`** for Shorts image archiving and for `snippet` in `videos.list`.
- **Time.** `$this->travelTo()` for relative dates and "refreshed N ago" copy.
- **`Storage::fake()`** in every image test, asserting `assertExists`/`assertMissing`.
- **Query counts** on the feed, to catch an N+1 the moment it appears.

### What is deliberately not tested

- Flux component internals and Tailwind classes.
- The YouTube IFrame API (third-party JS in an iframe; manual checklist instead).
- Fortify's auth flows — the starter kit's tests already cover them and stay untouched.
