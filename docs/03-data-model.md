# 03 — Data Model

SQLite, three tables. Existing starter-kit tables (`users`, `sessions`, `cache`, `jobs`,
`passkeys`) are untouched.

```
channels ──< videos
    │           │
    └──< refresh_runs
```

## `channels`

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | integer PK | no | — | |
| `youtube_channel_id` | string(32) | no | — | Canonical `UC…` ID. **Unique.** |
| `title` | string | no | — | Canonical name from YouTube, refreshed on demand |
| `custom_name` | string | yes | null | Your override; displayed everywhere when set |
| `handle` | string(64) | yes | null | `@handle` if known, for display and links |
| `avatar_url` | string | yes | null | Source URL, fallback if the local file is missing |
| `avatar_path` | string | yes | null | Archived file, relative to the configured disk |
| `uploads_playlist_id` | string(32) | yes | null | Stored for possible future backfill; unused |
| `is_enabled` | boolean | no | `true` | Disabled channels are not refreshed and not shown in the feed |
| `feed_etag` | string | yes | null | For conditional RSS requests |
| `feed_last_modified` | string | yes | null | For conditional RSS requests |
| `last_refreshed_at` | timestamp | yes | null | Last **successful** refresh |
| `last_refresh_error` | text | yes | null | Non-null means the last attempt failed |
| `created_at` / `updated_at` | timestamps | yes | — | |

**Indexes**

- `unique(youtube_channel_id)` — the duplicate guard; the "already added" error comes from a
  pre-check, but the constraint is the real one.
- `index(is_enabled)` — the feed and refresh both filter on it.

**Accessor:** `display_name` returns `custom_name ?? title`. Nothing in the UI reads `title`
directly.

## `videos`

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | integer PK | no | — | |
| `channel_id` | FK → channels | no | — | `cascadeOnDelete` |
| `youtube_video_id` | string(16) | no | — | The 11-char ID. **Unique.** Also the route key |
| `title` | string | no | — | **Archived at first ingest. Never updated.** |
| `description` | text | yes | null | Archived at first ingest. Never updated |
| `published_at` | timestamp | no | — | From the feed's `<published>` |
| `duration_seconds` | integer | yes | null | Null = unknown (no API key, quota, or live) |
| `thumbnail_url` | string | yes | null | Source URL; fallback when no local file |
| `thumbnail_path` | string | yes | null | Archived file, relative to the configured disk |
| `is_short` | boolean | yes | null | Null = not yet determined. Once set, never re-checked |
| `live_status` | string(16) | no | `'none'` | `none` / `live` / `upcoming` |
| `scheduled_start_at` | timestamp | yes | null | Premieres and scheduled streams |
| `watched_at` | timestamp | yes | null | Null = unwatched. Stores *when*, not just whether |
| `hidden_at` | timestamp | yes | null | Null = visible |
| `unavailable_at` | timestamp | yes | null | Deleted / private / region-blocked |
| `enriched_at` | timestamp | yes | null | Null = `videos.list` data still missing |
| `created_at` / `updated_at` | timestamps | yes | — | `created_at` is "when Calm Tube first saw it" |

**Indexes**

```php
$table->unique('youtube_video_id');
$table->index('published_at');                      // feed ordering
$table->index(['channel_id', 'published_at']);      // channel page
$table->index(['is_short', 'live_status', 'hidden_at', 'published_at']); // the feed filter
$table->index('watched_at');                        // unwatched filter
$table->index('enriched_at');                       // calm:enrich sweep
```

### Why `live_status` is a string, not an enum column

SQLite has no native enum; Laravel would emit a `varchar` with a check constraint that is
painful to alter. A plain string cast to a PHP enum gives the same type safety in application
code:

```php
protected function casts(): array
{
    return [
        'live_status' => LiveStatus::class,   // enum LiveStatus: string { case None = 'none'; ... }
        'published_at' => 'datetime',
        'watched_at' => 'datetime',
        // ...
        'is_short' => 'boolean',
        'is_enabled' => 'boolean',
    ];
}
```

Enum cases use TitleCase per the project's PHP conventions: `None`, `Live`, `Upcoming`.

### Nullable timestamps instead of booleans

`watched_at`, `hidden_at`, `unavailable_at` and `enriched_at` are all nullable timestamps
rather than `is_watched` / `is_hidden` flags. Same query cost, but they answer "when" for free
— useful given nothing is ever pruned. Model accessors (`isWatched()`, `isHidden()`) keep call
sites readable.

### The feed scope

Every exclusion rule lives in one query scope, so there is exactly one definition of "what
belongs in the feed":

```php
public function scopeInFeed(Builder $query): void
{
    $query->whereNull('hidden_at')
        ->whereNull('unavailable_at')
        ->where('live_status', LiveStatus::None)
        ->where(fn ($q) => $q->where('is_short', false)->orWhereNull('is_short'))
        ->whereHas('channel', fn ($q) => $q->where('is_enabled', true));
}
```

Note the `is_short` clause: **null is treated as not-a-Short**, so a video whose detection
failed is shown rather than silently swallowed. The probe is what decides; an unknown never
hides something you wanted.

## `refresh_runs`

Powers the "last refreshed" timestamp, per-channel refresh history and error display without
reading logs.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | integer PK | no | |
| `channel_id` | FK → channels | yes | Null for a whole-app run summary |
| `trigger` | string(16) | no | `manual` / `scheduled` |
| `status` | string(16) | no | `running` / `ok` / `degraded` / `failed` |
| `new_videos_count` | integer | no | Default 0 |
| `error_message` | text | yes | |
| `started_at` | timestamp | no | |
| `finished_at` | timestamp | yes | Null while running |

**Indexes:** `index(['channel_id', 'started_at'])`, `index('started_at')`.

`degraded` means "the RSS half worked, the API half didn't" — quota exhausted, no key, or an
API error. It is the state that tells you durations are missing but nothing is lost.

**Growth:** hourly scheduled refreshes over 30 channels is ~260k rows/year, which is more
history than is useful. A `calm:prune-runs` command (Phase 10) trims runs older than 30 days.
This is the one thing that *is* pruned — it's operational log data, not your archive.

## Relationships

```php
// Channel
public function videos(): HasMany;
public function refreshRuns(): HasMany;
public function latestVideo(): HasOne;          // ->latestOfMany('published_at')

// Video
public function channel(): BelongsTo;

// RefreshRun
public function channel(): BelongsTo;           // nullable
```

## Deletion behaviour

Deleting a channel **cascades** to its videos and refresh runs, dropping the archived
thumbnails with them (via a model event that unlinks files).

But deletion is deliberately *not* the primary action. The UI's main control is
**Disable**, which stops refreshes and removes the channel from the feed while keeping
everything. Hard delete sits behind a secondary path and a confirmation naming exact counts:

> Delete **Practical Engineering**? This permanently removes 143 archived videos (87 marked
> watched) and their thumbnails. To simply stop following it, disable it instead.

Rationale: the archive is the valuable part — those frozen titles and thumbnails cannot be
recovered from YouTube once re-titled. Disabling is reversible; deleting is not.

## Retention

Nothing in `channels` or `videos` is ever pruned. Watched and hidden videos are kept forever.

Scale check: 30 channels at ~100 uploads/year is 3,000 rows/year, so even a decade is 30k
rows — trivial for SQLite. Archived thumbnails at ~120 KB each are the real cost, roughly
**350 MB/year**, which is the deliberate price of the never-changing archive.

Two further reasons never to prune videos:

1. A pruned video still sitting in the RSS feed would be re-ingested and appear as new.
2. `watched_at` is the permanent record of what you've seen. Losing it means re-watching
   things by accident.

## Migrations in outline

Three migrations, generated with `php artisan make:migration --no-interaction`:

```
2026_XX_XX_000001_create_channels_table.php
2026_XX_XX_000002_create_videos_table.php
2026_XX_XX_000003_create_refresh_runs_table.php
```

```php
// create_channels_table
Schema::create('channels', function (Blueprint $table): void {
    $table->id();
    $table->string('youtube_channel_id', 32)->unique();
    $table->string('title');
    $table->string('custom_name')->nullable();
    $table->string('handle', 64)->nullable();
    $table->string('avatar_url')->nullable();
    $table->string('avatar_path')->nullable();
    $table->string('uploads_playlist_id', 32)->nullable();
    $table->boolean('is_enabled')->default(true)->index();
    $table->string('feed_etag')->nullable();
    $table->string('feed_last_modified')->nullable();
    $table->timestamp('last_refreshed_at')->nullable();
    $table->text('last_refresh_error')->nullable();
    $table->timestamps();
});

// create_videos_table
Schema::create('videos', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
    $table->string('youtube_video_id', 16)->unique();
    $table->string('title');
    $table->text('description')->nullable();
    $table->timestamp('published_at');
    $table->unsignedInteger('duration_seconds')->nullable();
    $table->string('thumbnail_url')->nullable();
    $table->string('thumbnail_path')->nullable();
    $table->boolean('is_short')->nullable();
    $table->string('live_status', 16)->default('none');
    $table->timestamp('scheduled_start_at')->nullable();
    $table->timestamp('watched_at')->nullable();
    $table->timestamp('hidden_at')->nullable();
    $table->timestamp('unavailable_at')->nullable();
    $table->timestamp('enriched_at')->nullable();
    $table->timestamps();

    $table->index('published_at');
    $table->index(['channel_id', 'published_at']);
    $table->index(['is_short', 'live_status', 'hidden_at', 'published_at']);
    $table->index('watched_at');
    $table->index('enriched_at');
});
```

## Factories

Both models need factories with states, used throughout the test suite:

```php
ChannelFactory:  ->disabled()  ->neverRefreshed()  ->failing()
VideoFactory:    ->watched()  ->hidden()  ->short()  ->live()  ->upcoming()
                 ->unenriched()  ->unavailable()  ->archived()   // has a local thumbnail
```

`VideoFactory` defaults should produce a normal, feed-visible, unwatched video with a
plausible duration (8–25 minutes) so that most tests need no state at all.

## No seeder for real channels

A `DatabaseSeeder` that inserts real YouTube channel IDs would make `migrate:fresh --seed`
hit the network on first refresh and tie the repo to someone's taste. Seeding is factory-based
and used only by tests. Your actual channels are added through the UI.
