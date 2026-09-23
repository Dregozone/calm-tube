# 07 — Open Questions

Decisions deferred, risks accepted, and ideas parked. Nothing here blocks implementation.

---

## Deferred features

### ~~Backfilling a channel's history~~ — built, because the premise was wrong

Originally deferred on the grounds that importing a back catalogue would flood the feed. Real
data overturned it. The RSS feed holds 15 entries **whatever they are**, and Shorts occupy the
same slots, so on a Shorts-heavy channel the long-form videos are pushed out before the app
ever sees them:

| Channel | Shorts in the window | Window spans | Videos reaching the feed |
| --- | --- | --- | --- |
| MoreMozi | 10/15 | **4.5 hours** | 5 |
| Alex Hormozi | 15/15 | 2 days | **0** |
| Vanessa Van Edwards | 15/15 | 20 days | **0** |

Two channels contributed nothing at all. The flooding worry was also wrong: the feed is
reverse-chronological, so recovered videos land in the past where they belong rather than
pushing anything off the top.

`calm:backfill` now walks the uploads playlist per channel, and an ordinary refresh walks it
back to the first known video whenever *every* feed entry is new, which is the signal that
the window may have overflowed. Costs about 208 units once and near nothing thereafter,
against a 10,000/day allowance.

### Marking watched at 90%

`ENDED` only fires if you watch the final frame, which is rare — most videos are abandoned in
the credits. Polling `getCurrentTime() / getDuration()` every few seconds and marking watched
past 90% would match reality better.

**Why it's deferred:** it needs a timer running during playback and a judgement about the
threshold, and the manual button already covers it. Worth revisiting after a few weeks of
real use — if "mark as watched" is being clicked constantly, build it.

### Clickable timestamps in descriptions

`04:12` in a description could call `player.seekTo(252)`. Genuinely useful for long videos,
and low risk. Deferred purely to keep Phase 8 small.

### Resuming playback

Storing `resume_seconds` and passing `start=` would let long videos continue where you left
off. Needs the same polling as the 90% rule, so the two should be built together or not at all.

### A "watch later" queue

Distinct from unwatched: an explicit, ordered, short list of things you've decided to watch.
Arguably the most useful missing feature, and also the one most likely to become another
backlog that generates guilt. Left out on purpose; reconsider only if the unwatched filter
proves insufficient.

### Local caching of channel avatars on the feed

Avatars are archived already, but only refreshed when channel metadata is refreshed. If a
creator changes their avatar, the archived one persists — consistent with the thumbnail
policy, though it was never explicitly decided. **Worth confirming:** should avatars freeze
like thumbnails, or track the channel's current one?

---

## Accepted risks

### The Shorts probe is undocumented

`HEAD /shorts/{id}` returning 200 vs. a redirect is observed behaviour, not a contract. If it
changes, the app silently falls back to "≤ 60s is a Short" and Shorts between 60 and 180
seconds start appearing in the feed.

**Detection:** the test suite pins both branches, so the behaviour change shows up as a
failing test only if fixtures are re-captured. In practice the first signal will be Shorts
appearing in the feed. If that happens, check the probe before anything else.

**Fallbacks, in order of preference:** switch `HEAD` to `GET`; tighten
`fallback_max_seconds`; add a per-video "this is a Short" manual override.

### `rel=0` doesn't do what its name implies

Since 2018 it limits end-screen suggestions to the same channel rather than removing them.
The overlay panel is the real mitigation, and it can only cover the *end* screen — end
**cards** during the final seconds of playback remain visible. The `pointer-events: none`
mask in Phase 10 is cosmetic and may break when YouTube changes its player layout.

### Legacy `/c/` URLs can't be resolved

There is no API parameter for old custom URLs. The app asks you to paste an `@handle` or a
video link instead.

**The alternative, if it ever becomes annoying:** fetch the channel page and read the
`<link rel="alternate" type="application/rss+xml" href="…channel_id=UC…">` tag. It's a single
request, reads one attribute, and never touches page content — but it is parsing HTML, which
the rest of the app deliberately avoids. The video-URL path covers the same ground with an
official endpoint, so this stays unbuilt unless there's a case it can't handle.

### Storage grows without bound

~350 MB/year of archived thumbnails, by design. At five years that's under 2 GB, which is
fine. If it ever isn't: drop archived images (not rows) for watched videos older than a year
and fall back to hotlinking. The rows, titles and watch history would stay.

### The RSS feed may simply not be there

Observed on 23 September 2026: `/feeds/videos.xml` returned `404` for all 26 followed
channels and for YouTube's own channel, from a browser as well as from the app. Reports of
intermittent 404s go back to late 2025 and there is no deprecation notice, so it may return.

The app now falls back to the uploads playlist, so a dead feed costs quota rather than
videos. The residual risk is that **discovery now needs an API key**: without one there is no
feed and no fallback, and nothing new arrives. If YouTube removes the feed for good, the
honest move is to make the uploads playlist the primary path and stop pretending the no-key
mode discovers anything.

---

## Questions worth answering after some real use

1. **Is hourly the right refresh interval?** It's a guess. Twice a day might suit the calm
   goal better — new videos arriving in batches is less pull than a feed that's always
   slightly different.
2. **Should the feed default to unwatched?** Currently it defaults to "all". If "unwatched" is
   what gets clicked every time, flip the default.
3. **Is 24 per page right?** Small enough to feel finite, large enough not to paginate
   constantly. Unverified.
4. **Do `refresh_runs` earn their keep?** If the per-channel error line is the only thing ever
   consulted, the table could collapse into columns on `channels` and delete a whole concept.
5. **Does the description need to be there at all?** Descriptions are increasingly sponsor
   copy and link farms. Collapsing them by default may be the right call.
6. **Does disabled-vs-deleted actually get used?** If every removal ends in a delete, the
   two-step flow is just friction.

---

## AI, deliberately out of scope

`laravel/ai` is installed for later. Nothing in Phases 0–10 uses it. The data model doesn't
block any of the following, and none of them should be built until the core tool has been
lived with:

| Idea | Note |
| --- | --- |
| **Description summaries** | Condense a sponsor-laden description to two useful sentences. The most clearly additive idea here — small, local, no ranking involved |
| **Topic tagging** | Tag videos from title + description so an archive of thousands stays searchable. Useful as the library grows; premature now |
| **Transcript summaries** | Would need captions, which means the API's caption endpoints and its licensing terms. Check the ToS position carefully before going near it |
| **"Is this worth my time?"** | A per-video relevance score. Tempting, and exactly the thing the app exists to escape — an algorithm deciding what gets attention. If ever built, it must rank nothing and reorder nothing; at most it annotates |
| **Natural-language search** | "That video about bridge foundations" over your own archive. Low risk, purely retrospective, genuinely in the spirit of the tool |

The principle: **AI may help you find something you already chose to follow. It must never
choose for you.** Anything that scores, sorts or recommends fails that test.

---

## Explicitly settled, for the record

So future sessions don't relitigate these:

| Decision | Settled as |
| --- | --- |
| Frontend | Livewire 4 single-file pages + Flux Free (already installed) |
| Auth | Fortify kept, single user, `auth` middleware, registration closed after setup |
| Database | SQLite |
| Refresh execution | Synchronous, `dispatchSync()`, no queue worker |
| Backfill on add | RSS only (~15 videos); `calm:backfill` reaches further on demand |
| Shorts | Excluded entirely. Probe + duration fallback. **No toggle to show them** |
| Live / upcoming | Stored, hidden until finished |
| Feed layout | One reverse-chronological grid, 24 per page, Previous/Next |
| Retention | Videos and channels kept forever; only `refresh_runs` are pruned |
| Channel removal | Disable is the default action; delete is secondary and cascades |
| Titles | Archived at first ingest, never updated, changes never stored or surfaced |
| Thumbnails | Archived locally, `maxresdefault` → `mqdefault`, served via a route |
| Playback speed | Per channel, not per video. Stored on `channels.playback_rate`, set from the watch page |
| End of video | Overlay covers the end screen, then a viewport-fixed modal counts down and returns you to your list. Both `wire:ignore` |
| Feed default | Unwatched on a fresh session; your last choice is remembered after that |
| Bulk triage | "Mark page watched" on the feed, "Mark all as watched" on a channel, both with Undo. Nothing is ever deleted or hidden by them |
| Resuming | Position stored per video, offered rather than applied, cleared on finish or restart |
| Descriptions | Closed by default; links inside them still work |
| Sampling | Per channel, opt-in, longest few per day. Length only — no model, no preference history. Sets aside, never deletes or hides |
| AI | Out of scope for the initial build |
