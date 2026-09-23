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

**Player:** 16:9, centred, and as large as the viewport allows. Its width is capped by the
height left over rather than by a fixed pixel figure —
`max-width: min(100%, calc((100dvh - 11rem) * 16 / 9 + 2rem))` — so the whole player is always
on screen and never smaller than it needs to be. Embed parameters and the IFrame API wiring
are specified in [05-youtube-integration.md](05-youtube-integration.md#the-embed).

**Playback speed** is a property of the channel, not of the video: some presenters are worth
watching at 1.5x or 2x every time, and choosing that once beats reaching for the player's menu
on every upload. The select on this page writes `channels.playback_rate`, applies immediately
to what is playing, and applies to every later video from that channel. Allowed rates come
from `calm-tube.player.playback_rates`, and a rate outside that list is refused rather than
handed to a player that would reject it anyway.

**Title** is the archived one — the same string you saw on the card, regardless of what the
video is called on YouTube today.

**Description** starts closed behind a "Show description" toggle. A description is mostly
links out — sponsors, socials, the author's other videos — and none of that should be in front
of you while you are deciding what to watch. Opened, it is rendered with `e()` then a linkifier
that wraps bare URLs in `<a target="_blank" rel="noopener noreferrer">`, newlines preserved with
`whitespace-pre-line`. Timestamps like `04:12` are left as
plain text in Phase 7 (making them seek the player is parked in open questions).

**"Open on YouTube"** links to `https://www.youtube.com/watch?v={id}`, `target="_blank"`,
`rel="noopener noreferrer"`. Always present, never hidden — it's the pressure valve that makes
the rest of the app's restrictions tolerable.

### The end-of-video panel

When the IFrame API reports `ENDED`, an overlay covers the player area before YouTube's
end-screen grid can be read, and the video is marked watched automatically. A separate modal,
fixed to the viewport, then counts down and returns you to the list you came from.

**Both are `wire:ignore`.** They are shown by toggling classes from JavaScript, and marking the
video watched re-renders the component; without `wire:ignore` Livewire's morph restores the
server-rendered `hidden` and the countdown runs invisibly to its end. That is not hypothetical
— it is what shipped first.

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

### Starting

The video you clicked plays without a second click: `autoplay=1`, plus a `playVideo()` on
ready in case the browser refused the first attempt. If it refuses both, the poster stays and
the play button is where it always was.

This is not the autoplay the app exists to avoid. That one chooses the *next* video for you,
and nothing here ever will. A part-watched video is the exception — it has a resume prompt to
put to you first, so it waits. `calm-tube.player.autoplay` turns it off.

### Resuming

The embedded player forgets where you were between visits, so the position is written to
`videos.resume_seconds` every ten seconds while the video plays, on pause, and on `pagehide`.
`saveProgress()` calls `skipRender()`: a round trip every ten seconds is cheap, re-rendering
the page around it is not.

Reopening a part-watched video **offers** to resume rather than seeking behind your back — a
video that silently starts in the middle feels broken, and sometimes you did mean to start
again. Below fifteen seconds in, or within fifteen seconds of the end, there is nothing worth
resuming and the prompt does not appear.

Finishing, replaying, starting again, and marking watched or unwatched all clear the position.

On a card it shows as a thin bar across the bottom of the thumbnail and a "stopped at 18:42"
line. No percentage and no label: it is a record of where you got to, not a nudge to go back.

### The end-card mask

YouTube draws clickable end cards over the last seconds of a video, inside the iframe, where
no embed parameter reaches them. For that stretch a transparent layer covers the picture and
swallows the clicks.

It stops short of the control bar, so the scrubber and volume stay reachable, and a click on
it pauses rather than doing nothing — pausing is what clicking a video is for, and the mask
should not take that away. Paused, it lifts: the picture is then yours to look at.

Remaining time is polled once a second rather than scheduled, because it moves with both
seeking and playback speed. `calm-tube.player.end_card_mask_seconds` sets the window, and 0
turns it off. It does not apply in fullscreen, where the iframe is on top of everything.

### The countdown

```
             ┌──────────────────────────────────┐
             │   Returning to your videos in    │
             │                                  │
             │                5                 │
             │                                  │
             │  Stay on this page to keep the   │
             │  countdown from finishing.       │
             │                                  │
             │         [   Go now   ]           │
             │         [  Stay here ]           │
             └──────────────────────────────────┘
```

Centred over the whole viewport rather than inside the player, so it is seen however far down
the page you have scrolled. If the player was fullscreen it is dropped out of fullscreen first,
because a fullscreen iframe sits above everything else.

The countdown returns you to the feed **as a full page visit**, carrying the filter and channel
you left it on, so a video just marked watched is gone from an unwatched list rather than
lingering in a cached grid.

Every way out of it: "Stay here", "Go now", the panel's own buttons, clicking the backdrop, or
Escape. Its length is `calm-tube.player.countdown_seconds`.

Leaving is automatic; arriving anywhere new never is.

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

Each row shows the archived avatar, the display name — a link to that channel's own page —
the video count, the handle, any non-normal playback speed, and either the last refresh time
or the error from the last attempt.

Disabled rows are dimmed and say so. `[ ⟳ ]` refreshes that channel alone, including a disabled
one: disabling stops the scheduled sweep touching it, but asking for it directly is still an
answer. `[ ⋯ ]` opens a small menu — **Edit**, **Disable** / **Enable**, and, after a separator,
the only destructive item in the app, **Delete**.

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
        │  Your name for it                            │
        │  ┌────────────────────────────────────────┐  │
        │  │ Practical Engineering                  │  │
        │  └────────────────────────────────────────┘  │
        │  Leave it empty to use the name YouTube      │
        │  gives it.                                   │
        │                                              │
        │  Playback speed                              │
        │  ┌────────────────────────────────────────┐  │
        │  │ Normal speed                         ▾ │  │
        │  └────────────────────────────────────────┘  │
        │                                              │
        │                     [ Cancel ]  [ Save ]     │
        └──────────────────────────────────────────────┘
```

Two things, both about how the channel appears to you: nothing here is sent to YouTube. An
emptied name field means "go back to what YouTube calls it", so it is stored as null rather
than an empty string. Speed is the same setting the watch page writes, offered here too
because the list is where you think about a channel as a whole.

Enabling and disabling is not in this modal — it is a single click in the row's menu, and
putting it behind a Save button would make a reversible act feel like a commitment.

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

Counts are real queries, not estimates: the total archived and how many of those you had
marked as watched, both emphasised in the sentence so the size of the loss is impossible to
skim past. **Disable instead** is offered on the same row, and is absent when the channel is
already disabled. `[ Delete permanently ]` is the danger variant and is the only control in
the app that isn't reversible.

---

## What a refresh reports

`RefreshResult` carries two counts because they answer different questions. `newVideos` is how
much the **archive** grew and is what `refresh_runs` records. `reachedFeed` is how many of those
you will actually **see**.

Only the second belongs in front of a person. On a Shorts heavy channel a refresh routinely
stores several videos and surfaces none of them, and "2 new videos" above an unchanged feed
reads as a bug in the app rather than as the app working exactly as designed.

`RefreshResult::summary()` is the single phrasing, used by every screen that reports a refresh
so they cannot drift apart:

> No new videos. 22 other uploads were Shorts or held back.

---

## Keeping the feed current without a scheduler

Nothing in this app is queued. `RefreshChannel` is not a `ShouldQueue` and is always
`dispatchSync()`d, so there is no worker to keep alive and the `jobs` table is never written
to. The manual refresh buttons always work.

The scheduler (`php artisan schedule:work`) is therefore optional, and on a local machine it
is usually not running. So opening a feed nobody has refreshed for
`calm-tube.refresh.auto_after_hours` refreshes it — through `wire:init`, **after** the grid has
rendered, so the page is on screen and usable while it runs. Opening the app is the trigger,
which is the one event a local app can rely on.

**What it finds is announced, not inserted.** Videos created after the page opened are held
behind a "12 new videos arrived while you were away — Show them" line, and only appear when you
ask. A grid that reshuffles under your cursor while you are reading it is precisely the feed
behaviour this app exists to avoid: leaving is automatic, arriving somewhere new is always your
click. Pressing **Refresh all** yourself holds nothing back — you asked, so you see the result.

Its refresh runs are recorded with the `stale` trigger, so the history says which refreshes
nobody asked for. Set the window to 0 to turn it off.

---

## Sampling a noisy channel

Some channels publish one substantial piece and a dozen offcuts of it in a single day.
Following them means either drowning or unfollowing, and both lose you the good one.

`channels.sample_limit` keeps the **longest few uploads of each day** and sets the rest aside.
Null — the default for every channel — means everything reaches the feed as before.

**Length is the whole of the rule.** On the channels this exists for it separates the talk
from the clips cut out of it almost perfectly, it needs no model and no history of what you
like, and it fits in one sentence. It is worth nothing on a channel whose uploads are all much
the same length, which is exactly why it is off unless you turn it on.

The unit is the **publication day**, not the refresh that found them: refreshes are an
implementation detail, days are not, and a day gives the same answer however many times it is
recalculated. Raising the limit brings videos straight back; clearing it returns everything.

**A day is recalculated from scratch every time it is touched.** A longer video arriving in the
evening takes its place among the keepers and the shortest of them drops out, because the rule
is "the longest few this channel published that day" and not "the first few we happened to
see". The day is not over until it is over.

The exception is anything you have **watched or started**: `scopeTouched()`. Those are never
set aside, and one that was set aside before you went and watched it on the channel page comes
back. A rule that recalculates must not take back a video you had already opened.

A video whose duration is unknown is **never** set aside. The API may not have answered yet,
and dropping a video because we could not measure it is the one outcome this must not produce.
`calm:sample` re-applies the rule once `calm:enrich` has filled those durations in.

**Nothing is deleted or hidden.** `sampled_out_at` is checked by `scopeInFeed()` and
deliberately *not* by `scopeViewable()`, so a set-aside video stays on its channel page, has
its own filter tab there, and is one click away. The feed says what the rule did rather than
quietly dropping things.

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
│         [ ⟳ Refresh ]  [ Open on YouTube ↗ ]                                │
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

Identical card component and pagination as the feed, scoped to one channel, and the browser
tab takes the channel's name. Editing and deleting stay on the list, where they sit beside
every other channel; this page is for reading, not administration.

Disabled channels still have a working page — you can browse the archive of a channel you've
stopped following. That is why the query behind it is `scopeViewable()` rather than
`scopeInFeed()`: same rules about Shorts, hidden, unavailable and live videos, no rule about
whether you still follow the channel.

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

Kept minimal: `g f` → feed, `g c` → channels, `/` focuses whatever this page's one text
control is (the channel filter on the feed, the add form on the channels page), and `Esc`
closes any modal (Flux default). No j/k card-by-card navigation — that's a scrolling-speed
feature, and speed isn't the goal.

The handler lives in `partials/keyboard.blade.php`, included by the layout and bound to the
document, which survives `wire:navigate`; a `window` flag stops a second visit stacking
another set of listeners. `g` waits 1.5 seconds for its destination and then forgets it, so a
stray press cannot hijack whatever you type a minute later. Every shortcut is ignored while
the focus is in a field.
