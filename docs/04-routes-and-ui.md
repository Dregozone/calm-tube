# 04 — Routes and UI

## Route table

All app routes sit behind `auth`. Livewire 4 page components are registered with
`Route::livewire()`; only the two image endpoints are plain controllers.

| Method | URI | Component / action | Name |
| --- | --- | --- | --- |
| GET | `/` | redirect to `feed` | `home` |
| GET | `/feed` | `pages::feed` | `feed` |
| GET | `/watch/{video}` | `pages::watch` | `videos.watch` |
| GET | `/channels` | `pages::channels.index` | `channels.index` |
| GET | `/channels/{channel}` | `pages::channels.show` | `channels.show` |
| GET | `/thumbnails/{video}` | `ThumbnailController` | `thumbnails.show` |
| GET | `/avatars/{channel}` | `AvatarController` | `avatars.show` |

```php
// routes/web.php
Route::redirect('/', '/feed')->name('home');

Route::middleware('auth')->group(function (): void {
    Route::livewire('feed', 'pages::feed')->name('feed');
    Route::livewire('watch/{video}', 'pages::watch')->name('videos.watch');
    Route::livewire('channels', 'pages::channels.index')->name('channels.index');
    Route::livewire('channels/{channel}', 'pages::channels.show')->name('channels.show');

    Route::get('thumbnails/{video}', ThumbnailController::class)->name('thumbnails.show');
    Route::get('avatars/{channel}', AvatarController::class)->name('avatars.show');
});
```

**Route model binding.** `Video` uses `youtube_video_id` as its route key, so watch URLs read
`/watch/dQw4w9WgXcQ` and map 1:1 to YouTube's own IDs. `Channel` uses `youtube_channel_id` for
the same reason.

```php
// Video.php
public function getRouteKeyName(): string
{
    return 'youtube_video_id';
}
```

### Everything else is a Livewire method, not a route

Adding, editing, disabling and deleting channels, marking watched, hiding videos and
refreshing are all component methods invoked with `wire:click`. There are no POST/PATCH/DELETE
routes, no form controllers and no CSRF plumbing to write.

| Action | Lives on | Method |
| --- | --- | --- |
| Add a channel | `pages::channels.index` | `addChannel()` |
| Rename / toggle / delete | `pages::channels.index`, `pages::channels.show` | `save()`, `toggleEnabled()`, `delete()` |
| Refresh all | `pages::feed` | `refreshAll()` |
| Refresh one channel | `channels.index`, `channels.show` | `refreshChannel($id)` |
| Mark watched / unwatched | `video-card`, `pages::watch` | `toggleWatched()` |
| Hide / unhide | `video-card` | `hide()`, `unhide()` |

### Authentication

Fortify stays as the starter kit configured it. After creating the single account,
registration is closed in `FortifyServiceProvider`:

```php
Fortify::ignoreRoutes();            // or, more surgically:
// config/fortify.php -> 'features' => [] minus Features::registration()
```

The `verified` middleware is **not** used — local mail would make email verification an
obstacle with no benefit. `auth` alone is the gate.

Nav: the Flux sidebar from the starter kit keeps exactly two items, **Feed** and **Channels**.
The dashboard placeholder page and its nav entry are removed in Phase 1.

---

## Screen 1 — Feed (`/feed`)

The home of the app. One reverse-chronological grid across all enabled channels.

```
┌──────────────────────────────────────────────────────────────────────────────┐
│ CALM TUBE          Feed   Channels                              [avatar ▾]   │
├──────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  Feed                                            Last refreshed 14 min ago   │
│                                                          [ ⟳ Refresh all ]   │
│                                                                              │
│  [ All | Unwatched ]        Channel: [ All channels        ▾ ]               │
│                                                                              │
│  ┌────────────────────┐  ┌────────────────────┐  ┌────────────────────┐      │
│  │                    │  │                    │  │░░░░░░░░░░░░░░░░░░░░│      │
│  │     thumbnail      │  │     thumbnail      │  │░░░  watched     ░░░│      │
│  │             ┌─────┐│  │             ┌─────┐│  │░░░░░░░░░░░ ┌─────┐░│      │
│  │             │12:04││  │             │ 7:31││  │░░░░░░░░░░░ │21:56│░│      │
│  └─────────────┴─────┘┘  └─────────────┴─────┘┘  └────────────┴─────┴─┘      │
│   How arch bridges       The quiet part of       Why this keeps happening    │
│   actually work          soldering                                           │
│   ◉ Practical Eng.       ◉ Applied Science       ◉ Technology Connections    │
│   3 days ago             5 days ago              1 week ago  ·  watched      │
│   [ ✓ watched ]  [ ✕ ]   [ ✓ watched ]  [ ✕ ]    [ ↺ unwatch ]  [ ✕ ]        │
│                                                                              │
│  ┌────────────────────┐  ┌────────────────────┐  ┌────────────────────┐      │
│  │        ...         │  │        ...         │  │        ...         │      │
│                                                                              │
│                   Showing 1–24 of 312                                        │
│                        [ ← Previous ]   [ Next → ]                           │
└──────────────────────────────────────────────────────────────────────────────┘
```

**Grid:** 1 column on mobile, 2 on `md`, 3 on `xl`. 24 per page. Cards are 16:9.

**Card anatomy** — thumbnail (archived, `/thumbnails/{id}`), duration badge bottom-right,
archived title (2 lines, then truncate), channel avatar + display name, relative date.
Watched cards are dimmed to ~55% opacity with a "watched" marker; hover reveals the actions.

**Filters:** `all | unwatched` segmented control and a channel dropdown. Both are Livewire
`#[Url]` properties, so the state lives in the query string (`/feed?filter=unwatched&channel=UC…`)
and survives refreshes and the back button. Changing a filter resets to page 1.

**No Shorts filter exists**, because Shorts are never stored in a displayable state. This is
intentional: see [01-overview.md](01-overview.md#non-goals).

**Pagination:** `WithPagination`, Previous/Next only, `scrollTo: false` so the page doesn't
jump. The count line makes the finiteness visible — that's the calm part.

### Feed states

**Empty — no channels yet**

```
                        ┌──────────────────────────┐
                        │                          │
                        │   Nothing here yet.      │
                        │                          │
                        │   Add a channel to start │
                        │   building your feed.    │
                        │                          │
                        │     [ Add a channel ]    │
                        └──────────────────────────┘
```

**Empty — channels exist, no videos yet**

> No videos yet. Refresh to fetch the latest uploads from your 4 channels.
> `[ ⟳ Refresh all ]`

**Empty — filter matches nothing**

> Nothing unwatched. You're all caught up. → `[ Show all videos ]`

A deliberately terminal, satisfying message. No "discover more" anything.

**Refreshing**

The button becomes a spinner via `wire:loading`, filters and cards disable, and the header
line reads "Refreshing 12 channels…". Implemented with `wire:loading.attr="disabled"` and
`data-loading:opacity-50`.

**Refresh finished** — a Flux toast, auto-dismissing after ~5s:

| Outcome | Message |
| --- | --- |
| New videos | "3 new videos from 2 channels." |
| Nothing new | "No new videos." |
| Partial failure | "2 new videos. 1 channel failed to refresh — see Channels." |
| All failed | "Refresh failed. Check your connection." (error variant) |

**Degraded banner** — a single dismissible line above the grid when relevant:

> ⓘ No YouTube API key set, so durations and Shorts filtering are unavailable. Add
> `YOUTUBE_API_KEY` to `.env`.

---

## Screen 2 — Watch (`/watch/{video}`)

One video, its metadata, and nothing that suggests another.

```
┌──────────────────────────────────────────────────────────────────────────────┐
│ CALM TUBE          Feed   Channels                              [avatar ▾]   │
├──────────────────────────────────────────────────────────────────────────────┤
│  ← Back to feed                                                              │
│                                                                              │
│      ┌────────────────────────────────────────────────────────────┐          │
│      │                                                            │          │
│      │                                                            │          │
│      │            youtube-nocookie.com embedded player            │          │
│      │                                                            │          │
│      │                                                            │          │
│      └────────────────────────────────────────────────────────────┘          │
│                                                                              │
│      How arch bridges actually work                                          │
│      ◉ Practical Engineering · 3 days ago · 12:04                            │
│                                                                              │
│      [ ✓ Mark as watched ]        [ Open on YouTube ↗ ]                      │
│                                                                              │
│      ────────────────────────────────────────────────────────────            │
│      The arch is the oldest structural form still in use, and                │
│      the reason is geometry rather than materials...                         │
│                                                                              │
│      Referenced in this video:                                               │
│      https://example.com/paper.pdf                                           │
│      ────────────────────────────────────────────────────────────            │
└──────────────────────────────────────────────────────────────────────────────┘
```

**Player:** 16:9 container, max-width ~960px, centred. Embed parameters and the IFrame API
wiring are specified in
[05-youtube-integration.md](05-youtube-integration.md#the-embed).

**Title** is the archived one — the same string you saw on the card, regardless of what the
video is called on YouTube today.

**Description** is rendered with `e()` then a linkifier that wraps bare URLs in
`<a target="_blank" rel="noopener noreferrer">`. Newlines preserved with `whitespace-pre-line`.
Collapsed to ~6 lines with a "Show more" toggle when long. Timestamps like `04:12` are left as
plain text in Phase 7 (making them seek the player is parked in open questions).

**"Open on YouTube"** links to `https://www.youtube.com/watch?v={id}`, `target="_blank"`,
`rel="noopener noreferrer"`. Always present, never hidden — it's the pressure valve that makes
the rest of the app's restrictions tolerable.

### The end-of-video panel

When the IFrame API reports `ENDED`, an overlay covers the player area before YouTube's
end-screen grid can be read, and the video is marked watched automatically.

```
      ┌────────────────────────────────────────────────────────────┐
      │                                                            │
      │                        Finished.                           │
      │                                                            │
      │            [ ← Back to feed ]                              │
      │            [ Next unwatched from Practical Engineering ]   │
      │            [ ↺ Replay ]                                    │
      │                                                            │
      └────────────────────────────────────────────────────────────┘
```

- The "next unwatched" button only renders when such a video exists; it is a **link, not an
  autoplay** — you click, or you don't.
- "Replay" calls `player.seekTo(0)` and hides the overlay.
- Marking watched happens via `$wire.markWatched()`, so a page reload shows the new state.

### Watch states

| State | Behaviour |
| --- | --- |
| Initial | Player renders with `autoplay=0`; you press play |
| Already watched | The action button reads "Mark as unwatched"; a subtle "watched 2 days ago" line appears |
| Embed blocked (error 101/150) | The player is replaced by a panel: "This video can't be embedded. [Open on YouTube ↗]" |
| Video unavailable (error 100 / `unavailable_at` set) | "This video is no longer available on YouTube." The archived title, description and thumbnail still display — the archive outlives the source |
| Missing duration | The metadata line simply omits it |
| Upcoming/live reached by direct URL | Renders normally with a plain note ("Scheduled for 14 March"); these are not linked from the feed |

---

## Screen 3 — Channels (`/channels`)

Management and the add form on one page.

```
┌──────────────────────────────────────────────────────────────────────────────┐
│  Channels                                                 [ ⟳ Refresh all ]  │
│                                                                              │
│  ┌────────────────────────────────────────────────────────────────────────┐  │
│  │ Add a channel                                                          │  │
│  │ ┌──────────────────────────────────────────────────┐  ┌─────────────┐  │  │
│  │ │ @handle, channel URL, or channel ID              │  │     Add     │  │  │
│  │ └──────────────────────────────────────────────────┘  └─────────────┘  │  │
│  │ Paste any YouTube channel link, an @handle, or a link to one of        │  │
│  │ its videos.                                                            │  │
│  └────────────────────────────────────────────────────────────────────────┘  │
│                                                                              │
│  4 channels · 3 enabled                                                      │
│                                                                              │
│  ┌────────────────────────────────────────────────────────────────────────┐  │
│  │ ◉  Practical Engineering                              143 videos       │  │
│  │    @PracticalEngineering · refreshed 14 min ago                        │  │
│  │                                    [●─ enabled]  [ ⟳ ]  [ Edit ] [ ⋯ ] │  │
│  ├────────────────────────────────────────────────────────────────────────┤  │
│  │ ◉  Applied Science                                     88 videos       │  │
│  │    @AppliedScience · refreshed 14 min ago                              │  │
│  │                                    [●─ enabled]  [ ⟳ ]  [ Edit ] [ ⋯ ] │  │
│  ├────────────────────────────────────────────────────────────────────────┤  │
│  │ ◉  Some Channel                                        12 videos       │  │
│  │    ⚠ Last refresh failed — couldn't reach the feed (2 hours ago)       │  │
│  │                                    [●─ enabled]  [ ⟳ ]  [ Edit ] [ ⋯ ] │  │
│  ├────────────────────────────────────────────────────────────────────────┤  │
│  │ ◌  Old Channel (disabled)                              31 videos       │  │
│  │    Hidden from your feed, not refreshed                                │  │
│  │                                   [─○ disabled]  [ ⟳ ]  [ Edit ] [ ⋯ ] │  │
│  └────────────────────────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────────────────────┘
```

Disabled rows are dimmed. `[ ⋯ ]` opens a small menu whose only destructive item is
**Delete channel…**.

### Adding a channel

One text input accepting every form listed in
[05-youtube-integration.md](05-youtube-integration.md#channel-resolution). On submit:

1. Validate not empty, `max:255`.
2. `ChannelResolver::parse()` — bad shape fails here, with no network call.
3. Pre-check `youtube_channel_id` for a duplicate.
4. `ChannelResolver::resolve()` — one API call.
5. Create the channel, archive its avatar, and immediately run a first refresh.
6. Toast: "Added Practical Engineering — 15 videos imported."

**Adding runs a refresh straight away**, so the feed is never empty after adding a channel.

### Add-form errors

| Cause | Message |
| --- | --- |
| Unparseable input | "That doesn't look like a YouTube channel. Try an @handle, a channel URL, or a link to one of its videos." |
| Already added | "You already follow **Practical Engineering**." with a link to its page |
| Already added but disabled | "**Old Channel** is already added but disabled. [Enable it]" |
| Not found (valid shape, no such channel) | "No channel found for `@thisdoesntexist`." |
| Legacy `/c/` URL | "YouTube's old /c/ links can't be resolved automatically. Open the channel and copy its @handle, or paste a link to one of its videos." |
| No API key | "Adding a channel needs a YouTube API key. Add `YOUTUBE_API_KEY` to `.env`, or paste a full `/channel/UC…` URL, which works without one." |
| Quota exceeded | "YouTube's API quota is used up for today. Try again after it resets (midnight Pacific)." |
| Network failure | "Couldn't reach YouTube. Check your connection and try again." |

Validation errors render inline under the field via Flux; success and failure of the
*network* steps use toasts.

### Edit (modal)

```
        ┌──────────────────────────────────────────────┐
        │  Edit channel                           [×]  │
        │                                              │
        │  Display name                                │
        │  ┌────────────────────────────────────────┐  │
        │  │ Practical Engineering                  │  │
        │  └────────────────────────────────────────┘  │
        │  Leave blank to use the channel's own name.  │
        │                                              │
        │  [●─] Enabled                                │
        │       Disabled channels aren't refreshed     │
        │       and don't appear in your feed.         │
        │                                              │
        │  Channel ID  UCMOqf8ab-42UUQIdVoKwjlQ        │
        │  Added       12 January 2026                 │
        │                                              │
        │                     [ Cancel ]  [ Save ]     │
        └──────────────────────────────────────────────┘
```

### Delete (confirmation modal)

```
        ┌──────────────────────────────────────────────┐
        │  Delete Practical Engineering?           [×] │
        │                                              │
        │  This permanently deletes:                   │
        │    · 143 archived videos                     │
        │    · 87 marked as watched                    │
        │    · 143 archived thumbnails                 │
        │                                              │
        │  Archived titles and thumbnails can't be      │
        │  recovered — YouTube may have changed them.  │
        │                                              │
        │  To stop following without losing anything,  │
        │  disable the channel instead.                │
        │                                              │
        │     [ Cancel ]  [ Disable ]  [ Delete ]      │
        └──────────────────────────────────────────────┘
```

Counts are real queries, not estimates. `[ Delete ]` is the danger variant and is the only
control here that isn't reversible.

---

## Screen 4 — Channel page (`/channels/{channel}`)

```
┌──────────────────────────────────────────────────────────────────────────────┐
│  ← Channels                                                                  │
│                                                                              │
│    ◉    Practical Engineering                                                │
│         @PracticalEngineering · 143 videos · 56 unwatched                    │
│         Refreshed 14 minutes ago                                             │
│                                                                              │
│         [ ⟳ Refresh this channel ]  [ Edit ]  [ Open on YouTube ↗ ]          │
│                                                                              │
│  [ All | Unwatched ]                                                         │
│                                                                              │
│  ┌────────────────────┐  ┌────────────────────┐  ┌────────────────────┐      │
│  │     thumbnail      │  │     thumbnail      │  │     thumbnail      │      │
│  └────────────────────┘  └────────────────────┘  └────────────────────┘      │
│   ... same cards as the feed ...                                             │
│                                                                              │
│                        [ ← Previous ]   [ Next → ]                           │
└──────────────────────────────────────────────────────────────────────────────┘
```

Identical card component and pagination as the feed, scoped to one channel. Disabled channels
still have a working page — you can browse the archive of a channel you've stopped following.

**Empty state:** "No videos yet. `[ ⟳ Refresh this channel ]`" — or, when the last refresh
failed, the error and its timestamp instead.

---

## Image routes

```php
final class ThumbnailController extends Controller
{
    public function __invoke(Video $video): Response|RedirectResponse
    {
        if ($video->thumbnail_path && Storage::disk(...)->exists($video->thumbnail_path)) {
            return response()->file(..., [
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ]);
        }

        return $video->thumbnail_url
            ? redirect()->away($video->thumbnail_url)
            : response()->file(public_path('images/thumbnail-placeholder.svg'));
    }
}
```

Three deliberate choices:

1. **A route, not `storage:link`.** `php artisan storage:link` needs Developer Mode or an
   admin shell on Windows and fails confusingly when it doesn't. A route sidesteps symlinks
   entirely.
2. **`immutable` caching.** Archived images never change, so the browser should never re-ask.
3. **Graceful fallback.** A failed download redirects to YouTube's CDN rather than showing a
   broken image; the next refresh retries the archive.

`AvatarController` is the same shape for `Channel`.

---

## Design language

Tailwind v4 and Flux Free only, staying with the starter kit's existing look.

- **Palette:** the starter kit's neutrals, light and dark both supported via the existing
  appearance setting. One accent colour, used only for focus rings and primary buttons.
- **Density:** generous whitespace, `max-w-7xl` container. The grid should feel underfilled
  rather than packed.
- **Motion:** none beyond Flux defaults and `wire:loading` opacity. No skeleton shimmer, no
  card hover-zoom, nothing that draws the eye.
- **Dates:** `->diffForHumans()` coarsely — "3 days ago", "last week". Never "4 minutes ago"
  on a video card; recency should not read as urgency.
- **Counts:** total library counts are fine ("143 videos"). Unwatched counts appear on the
  channel page only, never as a badge in the nav — a number that grows in the corner of the
  screen is a notification by another name.
- **`wire:navigate`** on all internal links for snappy transitions without a JS framework.

## Keyboard

Phase 9, and kept minimal: `/` focuses the feed filter, `g f` → feed, `g c` → channels,
`Esc` closes any modal (Flux default). No j/k card-by-card navigation — that's a
scrolling-speed feature, and speed isn't the goal.
