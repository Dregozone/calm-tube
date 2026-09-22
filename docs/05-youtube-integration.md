# 05 — YouTube Integration

Everything the app knows about YouTube comes from three places: the public RSS feed, the
official Data API v3, and one HTTP status code from the `/shorts/` URL.

| Source | Cost | Used for |
| --- | --- | --- |
| RSS feed | Free, no key | Discovering new uploads: ID, title, description, published date, thumbnail URL |
| Data API `videos.list` | 1 unit / 50 videos | Duration, live status, availability |
| Data API `channels.list` | 1 unit | Resolving a channel, its title and avatar |
| Data API `playlistItems.list` | 1 unit / 50 videos | Recovering uploads the 15-entry feed window pushed out |
| `HEAD /shorts/{id}` | Free, no key | Is this a Short? |

---

## The RSS feed

```
https://www.youtube.com/feeds/videos.xml?channel_id=UCMOqf8ab-42UUQIdVoKwjlQ
```

An Atom feed of the channel's **15 most recent uploads**. No key, no quota, no auth. This is
the discovery backbone; the API is only ever an enrichment step.

### Format

```xml
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns:yt="http://www.youtube.com/xml/schemas/2015"
      xmlns:media="http://search.yahoo.com/mrss/"
      xmlns="http://www.w3.org/2005/Atom">
  <id>yt:channel:MOqf8ab-42UUQIdVoKwjlQ</id>
  <yt:channelId>MOqf8ab-42UUQIdVoKwjlQ</yt:channelId>
  <title>Practical Engineering</title>
  <link rel="alternate" href="https://www.youtube.com/channel/UCMOqf8ab-42UUQIdVoKwjlQ"/>
  <author>
    <name>Practical Engineering</name>
    <uri>https://www.youtube.com/channel/UCMOqf8ab-42UUQIdVoKwjlQ</uri>
  </author>
  <published>2016-01-29T04:06:47+00:00</published>

  <entry>
    <id>yt:video:dQw4w9WgXcQ</id>
    <yt:videoId>dQw4w9WgXcQ</yt:videoId>
    <yt:channelId>UCMOqf8ab-42UUQIdVoKwjlQ</yt:channelId>
    <title>How Arch Bridges Actually Work</title>
    <link rel="alternate" href="https://www.youtube.com/watch?v=dQw4w9WgXcQ"/>
    <published>2026-03-11T14:00:09+00:00</published>
    <updated>2026-03-14T08:21:55+00:00</updated>
    <media:group>
      <media:title>How Arch Bridges Actually Work</media:title>
      <media:content url="https://www.youtube.com/v/dQw4w9WgXcQ?version=3" type="application/x-shockwave-flash" width="640" height="390"/>
      <media:thumbnail url="https://i4.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg" width="480" height="360"/>
      <media:description>The arch is the oldest structural form...</media:description>
      <media:community>
        <media:starRating count="12043" average="4.95" min="1" max="5"/>
        <media:statistics views="284119"/>
      </media:community>
    </media:group>
  </entry>
  <!-- 14 more entries -->
</feed>
```

### What we take, and what we pointedly don't

| Element | Stored as | Notes |
| --- | --- | --- |
| `yt:videoId` | `youtube_video_id` | The join key for everything |
| `title` | `title` | **Written once, at insert. Never updated.** |
| `media:group/media:description` | `description` | Also written once only |
| `published` | `published_at` | RFC 3339; `Carbon::parse()` handles it |
| `media:thumbnail@url` | `thumbnail_url` | The `hqdefault` fallback URL; the archiver prefers `maxresdefault` |
| `feed/title` | — | Cross-check only; the channel title comes from the API |
| `media:statistics@views` | **not stored** | View counts are exactly the social-proof signal this app exists to avoid |
| `media:starRating` | **not stored** | Same |
| `updated` | **not stored** | This is how retitles arrive; ignoring it is the whole point |

### Parsing

```php
$xml = simplexml_load_string($body);
$yt = $xml->getNamespaces(true)['yt'] ?? 'http://www.youtube.com/xml/schemas/2015';

foreach ($xml->entry as $entry) {
    $ytChild = $entry->children($yt);
    $media = $entry->children('http://search.yahoo.com/mrss/')->group;
    // (string) $ytChild->videoId, (string) $entry->title, ...
}
```

`simplexml` ships with PHP; no package needed. Guard against a non-XML body (YouTube
occasionally returns an HTML error page) by checking for a parse failure and treating it as a
feed error.

### Conditional requests

The feed honours `ETag` and `Last-Modified`. Storing them per channel turns an idle refresh
into a `304` with an empty body:

```php
Http::withHeaders(array_filter([
    'If-None-Match' => $channel->feed_etag,
    'If-Modified-Since' => $channel->feed_last_modified,
]))->timeout(10)->retry(2, 1000)->get($feedUrl);
```

A `304` finishes the refresh immediately with zero new videos and no parsing.

### Known limitations

- **15 entries only.** A channel posting 16 videos between refreshes loses the oldest ones
  permanently. At an hourly schedule this cannot realistically happen.
- **15 entries, whatever they are.** Shorts occupy the same slots, so a channel posting them
  heavily pushes its long-form videos out of the window within hours. This is not theoretical:
  one real channel's entire window spanned 4.5 hours, and two contributed nothing to the feed
  at all. `calm:backfill` and the refresh-time overflow recovery exist because of this.
- **Live and premiere behaviour is inconsistent.** Upcoming premieres usually appear at
  announcement; live streams often appear only once finished. Both are handled by the live
  status rules below rather than trusted from the feed.
- **Feeds lag slightly**, typically minutes behind an upload. Irrelevant here.
- **Deleted videos vanish from the feed silently.** The app detects that through the API
  instead (see [availability](#availability)).

---

## The Data API

Base: `https://www.googleapis.com/youtube/v3/`. Key via the `key` query parameter, read from
`config('calm-tube.api_key')`. Create a key at console.cloud.google.com with **YouTube Data
API v3** enabled; restrict it to that API.

Only two endpoints are used. `search.list` is **never** called — it costs 100 units and its
results are ranked, which is the wrong shape for this app in both senses.

### `videos.list` — enrichment

```
GET /youtube/v3/videos
  ?part=contentDetails,liveStreamingDetails,status
  &id=ID1,ID2,…up to 50
  &key=…
```

```json
{
  "items": [{
    "id": "dQw4w9WgXcQ",
    "contentDetails": {
      "duration": "PT12M4S",
      "dimension": "2d",
      "definition": "hd",
      "caption": "true",
      "licensedContent": true
    },
    "status": {
      "uploadStatus": "processed",
      "privacyStatus": "public",
      "embeddable": true
    }
  }]
}
```

**`snippet` is deliberately omitted from `part`.** It costs nothing extra, but it carries
`title` and `description`, and having them in the response is a standing invitation for a
future change to "helpfully" refresh them — which would destroy the archive guarantee. The
fields we need can all be derived without it.

**Batching:** chunk IDs 50 at a time (`collect($ids)->chunk(50)`). Each call is 1 unit
regardless of how many IDs or parts it carries, so a batch of 50 costs the same as a batch
of 1. Never call this per video.

### Live status without `snippet`

`snippet.liveBroadcastContent` is the direct answer, but the same conclusion comes from
`liveStreamingDetails`, which carries no title:

| `liveStreamingDetails` | Meaning | Stored |
| --- | --- | --- |
| absent | Ordinary uploaded video | `live_status = none` |
| `scheduledStartTime`, no `actualStartTime` | Premiere or scheduled stream | `upcoming`, `scheduled_start_at` set |
| `actualStartTime`, no `actualEndTime` | Live right now | `live` |
| both present | Stream finished; now an ordinary video | `none` |

Only `none` appears in the feed. An `upcoming` or `live` row is stored but invisible, and
becomes visible automatically once a later refresh re-checks it. That re-check is what
`calm:enrich` does: it sweeps rows that are `upcoming`/`live` or have `enriched_at IS NULL`,
and is the one place where an existing video row is updated — durations and status only,
never title or thumbnail.

### Availability

IDs sent in the request but absent from `items` are deleted, private, or otherwise gone.
Those get `unavailable_at = now()` and drop out of the feed, while their archived title,
description and thumbnail remain readable on their watch page. A video that YouTube has
removed still exists in your library, which is a feature of an archive.

### `channels.list` — resolution and metadata

```
GET /youtube/v3/channels?part=snippet,contentDetails&id=UC…&key=…
GET /youtube/v3/channels?part=snippet,contentDetails&forHandle=@name&key=…
GET /youtube/v3/channels?part=snippet,contentDetails&forUsername=legacy&key=…
```

```json
{
  "items": [{
    "id": "UCMOqf8ab-42UUQIdVoKwjlQ",
    "snippet": {
      "title": "Practical Engineering",
      "customUrl": "@practicalengineering",
      "thumbnails": { "high": { "url": "https://yt3.ggpht.com/…=s800-c-k-c0x00ffffff-no-rj" } }
    },
    "contentDetails": { "relatedPlaylists": { "uploads": "UUMOqf8ab-42UUQIdVoKwjlQ" } }
  }]
}
```

An empty `items` array means "no such channel" — a 200 response, not a 404. Handle it
explicitly.

### Errors

| HTTP | `error.errors[0].reason` | Handling |
| --- | --- | --- |
| 403 | `quotaExceeded` | `QuotaExceededException`; abort enrichment, keep RSS data, banner |
| 403 | `forbidden` / key restricted | Log at `error`, treat as unconfigured |
| 400 | `keyInvalid` | Same, with a message naming the key |
| 404 | — | Only for malformed paths; treat as a client bug |
| 5xx | — | `Http::retry(2, 1000)`, then degrade |

---

## Quota

The default project allowance is **10,000 units/day**, resetting at midnight US Pacific.

| Operation | Cost |
| --- | --- |
| `videos.list` (any number of parts, up to 50 IDs) | 1 |
| `channels.list` | 1 |
| `playlistItems.list` (up to 50 videos) | 1 |
| RSS fetch | 0 |
| Shorts probe | 0 |
| `search.list` (never used) | 100 |

### Estimate for this usage

Assume 30 channels, hourly scheduled refreshes, and roughly 10 new videos per day across all
of them.

| Activity | Calls/day | Units/day |
| --- | --- | --- |
| RSS polling — 30 channels × 24 runs | 720 | **0** |
| Enriching ~10 new videos (one batch) | 1 | 1 |
| `calm:enrich` retry sweep | 1 | 1 |
| Adding a channel (occasional) | ~1 | 1 |
| Shorts probes for short candidates | ~3 | **0** |
| **Total** | | **≈ 3 units/day** |

That is **0.03% of the daily allowance**. Even a pathological case — re-enriching an entire
5,000-video library in one go — costs 100 units. Quota is effectively a non-issue; the
handling exists for correctness, not because it will be hit.

The 720 daily RSS requests are the real external footprint, and most return `304` thanks to
conditional headers. If the hourly schedule ever feels excessive, lower it; nothing in the
design depends on the interval.

---

## Duration parsing

`contentDetails.duration` is an ISO 8601 duration: `PT12M4S`, `PT1H2M3S`, `PT45S`, `P0D`.

```php
final class DurationParser
{
    public function toSeconds(?string $iso): ?int
    {
        if ($iso === null || $iso === '' || $iso === 'P0D') {
            return null;   // live, or still processing
        }

        try {
            $interval = new DateInterval($iso);
        } catch (Exception) {
            return null;
        }

        $seconds = ($interval->d * 86400)
            + ($interval->h * 3600)
            + ($interval->i * 60)
            + $interval->s;

        return $seconds > 0 ? $seconds : null;
    }

    public function toHuman(?int $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }

        return $seconds >= 3600
            ? sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60)
            : sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
```

`DateInterval` parses the full grammar natively — no regex, no package. Edge cases to test:

| Input | Seconds | Human |
| --- | --- | --- |
| `PT45S` | 45 | `0:45` |
| `PT12M4S` | 724 | `12:04` |
| `PT1H2M3S` | 3723 | `1:02:03` |
| `PT1H` | 3600 | `1:00:00` |
| `P1DT2H` (long stream) | 93600 | `26:00:00` |
| `P0D` (live) | `null` | omitted from the UI |
| `null` / garbage | `null` | omitted from the UI |

`toHuman` is a computed accessor (`$video->duration_for_humans`), not a stored column — the
"human-readable format" requirement is a presentation concern and storing it would duplicate
state.

---

## Channel resolution

`ChannelResolver::parse()` is pure and makes no network call, so every row below is a unit
test.

| Input | Parsed as | API call |
| --- | --- | --- |
| `UCMOqf8ab-42UUQIdVoKwjlQ` | `channel_id` | `channels.list?id=` |
| `https://www.youtube.com/channel/UC…` | `channel_id` | `channels.list?id=` |
| `https://www.youtube.com/@handle` | `handle` | `channels.list?forHandle=` |
| `https://www.youtube.com/@handle/videos` | `handle` | `channels.list?forHandle=` |
| `@handle` | `handle` | `channels.list?forHandle=` |
| `handle` (bare word, no slashes/dots) | `handle` | `channels.list?forHandle=` |
| `https://www.youtube.com/user/LegacyName` | `username` | `channels.list?forUsername=` |
| `https://www.youtube.com/watch?v=VIDEOID` | `video_id` | `videos.list?part=snippet` → `channelId` |
| `https://youtu.be/VIDEOID` | `video_id` | as above |
| `https://www.youtube.com/shorts/VIDEOID` | `video_id` | as above |
| `https://www.youtube.com/c/CustomName` | `legacy_custom` | **unsupported** — friendly error |
| anything else | — | `UnresolvableChannelException` |

Notes:

- A channel ID is `UC` followed by 22 characters of `[A-Za-z0-9_-]`. Validate that shape
  before spending a call.
- `forHandle` accepts the handle with or without the leading `@`; send it with.
- **The video-URL path is the recommended escape hatch.** Any link to any video on a channel
  resolves to that channel, which covers `/c/` URLs, mobile share links and shortened links
  without special cases. It is the only place `part=snippet` is requested — there is no
  `videos` row to corrupt, since nothing has been stored yet.
- `/c/` custom URLs have no API parameter. The honest options are (a) tell the user to paste
  an `@handle` or video link, or (b) read the channel page's `<link rel="alternate">` RSS tag.
  The app does (a); (b) is parked in open questions.

### Without an API key

Only `channel_id` inputs resolve, using the RSS feed itself for the title:

```
GET https://www.youtube.com/feeds/videos.xml?channel_id=UC…  →  <title> and <author><name>
```

No avatar is available in that mode, so the UI falls back to a monogram tile. Every other
input form returns the "adding a channel needs an API key" error described in
[04-routes-and-ui.md](04-routes-and-ui.md#add-form-errors).

---

## Shorts detection

**The problem:** there is no `isShort` field. Not in the RSS feed, not in `videos.list`, not
anywhere in the Data API. Shorts are ordinary videos with a different URL surface.

**Why duration alone fails:** YouTube raised the Shorts limit to 3 minutes in October 2024.
A duration cutoff generous enough to catch modern Shorts (180s) also catches the genuinely
short real videos worth keeping — exactly the 1.5–2.5 minute range.

**The signal that works:** `https://www.youtube.com/shorts/{id}` returns **200** for a real
Short, and **redirects to `/watch?v={id}`** for anything else. One status code, one request,
no quota, no parsing.

**It needs a consent cookie.** Without one, YouTube answers every EU and UK request with a
`302` to `consent.youtube.com`, whatever the video is. Sending `Cookie: SOCS=CAI` makes it
serve the real answer. The older `CONSENT=YES+1` cookie no longer works — verified against
live YouTube:

| Cookie sent | 6s Short | 17s Short | 954s ordinary video |
| --- | --- | --- | --- |
| none | 302 → consent | 302 → consent | 302 → consent |
| `CONSENT=YES+1` | 302 → consent | 302 → consent | 302 → consent |
| **`SOCS=CAI`** | **200** | **200** | **303 → /watch** |

The cookie records a consent choice. It carries no account, no session and no identity, and
without it the probe cannot see the answer at all.

**Only a redirect to `/watch` is an answer.** This is the lesson from getting it wrong: the
first implementation treated any `3xx` as "ordinary video", so the consent bounce marked
every probed video as not-a-Short — 136 real Shorts went undetected in a 390-video library.
A redirect anywhere else says nothing about the video and must fall through to the duration
rule instead.

### Algorithm

```php
public function isShort(Video $video): ?bool
{
    // 1. Already decided. Never re-check.
    if ($video->is_short !== null) {
        return $video->is_short;
    }

    // 2. Duration gate — no request for anything over the ceiling.
    $duration = $video->duration_seconds;
    if ($duration !== null && $duration > config('calm-tube.shorts.probe_max_seconds')) {
        return false;
    }

    // 3. Probe.
    if (config('calm-tube.shorts.probe')) {
        try {
            $response = Http::withOptions(['allow_redirects' => false])
                ->withHeaders(['Cookie' => 'SOCS=CAI'])
                ->timeout(5)
                ->head("https://www.youtube.com/shorts/{$video->youtube_video_id}");

            if ($response->status() === 200) {
                return true;
            }

            // Only the watch page. A consent or sign-in redirect is not an answer.
            if ($this->redirectsToWatchPage($response->header('Location'))) {
                return false;
            }
        } catch (ConnectionException $e) {
            Log::channel('calm')->warning('Shorts probe failed', [...]);
        }
    }

    // 4. Fallback: the conservative duration rule.
    return $duration !== null
        ? $duration <= config('calm-tube.shorts.fallback_max_seconds')
        : null;   // unknown — and unknown is shown, not hidden
}
```

### Decision table

| Duration | Probe result | `is_short` | Requests made |
| --- | --- | --- | --- |
| 724s | — | `false` | 0 |
| 181s | — | `false` | 0 |
| 95s | 200 | `true` | 1 |
| 95s | 303 → `/watch` | `false` | 1 |
| 95s | 302 → `consent.youtube.com` | `false` (fallback: > 60s) | 1 |
| 42s | 302 → `consent.youtube.com` | `true` (fallback: ≤ 60s) | 1 |
| 95s | connection error | `false` (fallback: > 60s) | 1 |
| 42s | connection error | `true` (fallback: ≤ 60s) | 1 |
| `null` (no API key) | 200 | `true` | 1 |
| `null` (no API key) | error | `null` → **shown in the feed** | 1 |

The asymmetry in the last two rows is deliberate: when the app genuinely can't tell, it shows
the video. A leaked Short is a minor annoyance; a silently swallowed video you wanted is a
trust problem.

### Cost and caching

The duration gate means the probe only runs for videos under 3 minutes — typically a handful
per day. The result is written to `is_short` at ingest and **never re-checked**, so the
lifetime cost is one `HEAD` per short candidate, ever.

### Limitations, stated honestly

- **Undocumented behaviour.** This redirect is not a contract; YouTube could change it. If it
  does, the fallback silently takes over and Shorts between 60s and 180s start appearing. The
  test suite pins both paths so a change is visible when it happens.
- **The consent cookie is the fragile part.** Google has changed it once already
  (`CONSENT` → `SOCS`). If it changes again, every probe returns the consent redirect, which
  is now correctly read as "no answer", so detection falls back to the 60-second rule rather
  than silently marking Shorts as ordinary videos. That is the safe direction to fail in, but
  Shorts between 60s and 180s would start appearing, which is the signal to check this.
- **`HEAD` may be rejected.** If YouTube ever stops answering `HEAD` here, switch to `GET`
  with `allow_redirects => false` and discard the body — the status code is all we read.
- **Retroactive conversions.** A video's Short status is fixed at ingest. This has never been
  observed to change, but if it did, the app wouldn't notice.
- **Nothing is deleted.** Shorts are stored with `is_short = true` and simply never displayed.
  Storing them is what stops the app re-probing the same video on every refresh, and their
  thumbnails are never downloaded, so they cost 1 row and nothing else.

---

## Image archiving

Thumbnails and avatars are downloaded once and served locally so that a later swap on YouTube
can't change what you see. Thumbnail URLs follow a fixed pattern:

```
https://i.ytimg.com/vi/{VIDEO_ID}/maxresdefault.jpg   1280×720, 16:9, ~120 KB, not always present
https://i.ytimg.com/vi/{VIDEO_ID}/sddefault.jpg        640×480, 4:3 with bars
https://i.ytimg.com/vi/{VIDEO_ID}/hqdefault.jpg        480×360, 4:3 with bars  ← what RSS gives
https://i.ytimg.com/vi/{VIDEO_ID}/mqdefault.jpg        320×180, 16:9, ~12 KB, always present
```

**The URL is never constructed.** Google's documentation asks applications to use thumbnail
URLs exactly as returned, and real data shows why: the feeds return sharded hosts like
`i1.ytimg.com` through `i4.ytimg.com`, which no guessed pattern would have produced. Where a
response carries a whole thumbnails map — `playlistItems.list` does — the largest variant
present is chosen, because YouTube only includes `maxres` for uploads that have one.

The consequence is that RSS-discovered videos archive `hqdefault`, which is 480×360 with
letterbox bars. Cropping that to 16:9 is the UI's job (`object-cover` on a 16:9 container
removes exactly the bars), and it keeps the archive honest: what is stored is what YouTube
served.

A missing image is answered with a tiny grey placeholder rather than a 404, so anything under
`images.minimum_bytes` (1 KB) is rejected and the video falls back to hotlinking until the
next `calm:archive` retries it.

Avatars come from `channels.list` → `snippet.thumbnails.high.url` and are re-fetched only when
you explicitly refresh channel metadata, not on every video refresh.

**Storage:** measured, not estimated. A real 758-image archive came to **16 MB, averaging
22 KB per thumbnail** — far below the 120 KB the original design assumed, because RSS supplies
`hqdefault` rather than `maxres`. At ~3,000 videos a year that is roughly **66 MB/year**, and
Shorts are excluded entirely, so the real figure is lower still.

---

## The embed

```
https://www.youtube-nocookie.com/embed/{VIDEO_ID}
    ?rel=0
    &modestbranding=1
    &playsinline=1
    &iv_load_policy=3
    &enablejsapi=1
    &autoplay=0
    &origin={APP_URL}
```

| Parameter | Effect |
| --- | --- |
| `youtube-nocookie.com` | No tracking cookies until playback starts |
| `rel=0` | **Does not remove related videos.** Since September 2018 it only restricts end-screen suggestions to the *same channel*. This is why the overlay panel exists |
| `modestbranding=1` | Was "hide the logo"; deprecated and now largely a no-op. Harmless, kept for older clients |
| `playsinline=1` | Prevents forced fullscreen on iOS |
| `iv_load_policy=3` | Hides annotations |
| `enablejsapi=1` | Required for the IFrame API |
| `autoplay=0` | Explicit. Nothing plays until you press play |
| `origin` | Required alongside `enablejsapi` for `postMessage` security |

Deliberately **not** used: `loop`, `playlist`, `autoplay=1`, `start`, `mute`.

---

## The IFrame Player API

```html
<script src="https://www.youtube.com/iframe_api"></script>
```

The script itself must load from `youtube.com` even when the player is hosted on
`youtube-nocookie.com`. To keep the nocookie host, pass it explicitly when constructing:

```js
new YT.Player('calm-player', {
    host: 'https://www.youtube-nocookie.com',
    videoId: 'dQw4w9WgXcQ',
    playerVars: { rel: 0, modestbranding: 1, playsinline: 1, iv_load_policy: 3, origin: location.origin },
    events: { onReady, onStateChange, onError },
});
```

Wire it up inside an Alpine component on the watch page, with `@script` so it survives
`wire:navigate` transitions.

### Events used

| Event | Value | Handling |
| --- | --- | --- |
| `onReady` | — | Enable the manual controls |
| `onStateChange` | `YT.PlayerState.ENDED` (0) | **Show the overlay panel, call `$wire.markWatched()`** |
| `onStateChange` | `PLAYING` (1) | Hide the overlay if it's showing (covers replay) |
| `onStateChange` | `PAUSED` (2), `BUFFERING` (3), `CUED` (5), `UNSTARTED` (-1) | Ignored |
| `onError` | 2 | Invalid video ID — "This video couldn't be loaded" |
| `onError` | 5 | HTML5 player error — same panel, suggest opening on YouTube |
| `onError` | 100 | Video removed or private — mark `unavailable_at`, show the archived metadata |
| `onError` | 101 / 150 | Embedding disabled by the owner — "Open on YouTube ↗" |

`onError` 100 is worth wiring back to the server with `$wire.markUnavailable()`: it is the
fastest signal that a video has gone, faster than the next enrichment sweep.

### The end-screen overlay

On `ENDED`, an absolutely-positioned panel covers the player area. YouTube's end screen
appears at the same moment, so the overlay must be in the DOM and merely hidden, not created
on the fly — a render delay is a window into exactly the grid we're avoiding.

```html
<div class="relative aspect-video">
    <div id="calm-player" class="absolute inset-0"></div>
    <div x-show="ended" x-cloak class="absolute inset-0 z-10 flex flex-col …">
        …Back to feed / Next unwatched / Replay…
    </div>
</div>
```

**The limitation that can't be fully solved:** end *cards* — the small clickable overlays
creators add in the final ~20 seconds — appear *during* playback, before `ENDED` fires. They
can't be covered by an opaque panel without also covering the player controls.

The optional mitigation (Phase 10) is a `pointer-events: none` overlay for the last 20 seconds:
it visually masks the card area while clicks fall through to the player underneath. It works,
but it's a cosmetic patch on someone else's UI and may need adjusting if YouTube changes
layout — hence optional.

### Marking as watched

`ENDED` fires only if you watch to the last frame, which in practice you often don't. Two
complementary paths:

1. **Automatic** on `ENDED`.
2. **Manual** via the "Mark as watched" button, always available.

A third option — polling `getCurrentTime()` and marking at 90% — is deliberately deferred; see
[07-open-questions.md](07-open-questions.md).
