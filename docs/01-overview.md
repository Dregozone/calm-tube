# 01 — Overview

## What this is

**Calm Tube** is a personal, local-only Laravel app for following a hand-picked set of YouTube
channels without ever loading youtube.com's homepage, recommendation rail or sidebar.

It runs on one machine (Herd, `http://calm-tube.test`), for one person. It is not a product,
has no multi-user story, and will never be deployed.

## Goals

1. **Follow specific creators, not a platform.** The only videos that exist in the app are
   uploads from channels I explicitly added.
2. **A feed that ends.** A finite, reverse-chronological grid with real pagination. When you
   reach the bottom of a page, you've reached the bottom.
3. **Watch without the pull.** Video plays in the official embedded player; when it ends, a
   calm panel covers the end-screen suggestions instead of offering four more videos.
4. **An archive, not a mirror.** Titles and thumbnails are captured at first ingest and never
   change afterwards, even when the uploader swaps them days later to chase the algorithm.
5. **Calm by construction.** Removing temptation beats resisting it, so the tempting things are
   absent from the data model, not hidden behind a setting.

## Non-goals

- **No recommendations, no ranking, no "for you".** Ordering is `published_at DESC`. Nothing else.
- **No infinite scroll, no autoplay chains.** Pagination is explicit; nothing plays on its own.
- **No comments, no likes, no subscriber counts, no view counts.** None of it is stored.
- **No Shorts.** Not hidden behind a toggle — excluded from the feed entirely. See
  [05-youtube-integration.md](05-youtube-integration.md#shorts-detection).
- **No search across YouTube.** The app cannot discover a channel you haven't already chosen.
- **No notifications, no badges, no unread counts on the tab title.**
- **No multi-user, no sharing, no sync, no deployment.**
- **No AI features for now.** The SDK is installed for later; candidate uses are parked in
  [07-open-questions.md](07-open-questions.md).

## Guiding principles

**Absence over configuration.** If a feature would be bad for me, it shouldn't ship with an
off switch — it shouldn't ship. Settings are an invitation to relapse.

**Boring by default.** No countdowns, no "LIVE" badges, no "posted 4 minutes ago" urgency.
Relative dates are coarse ("3 days ago"), not precise.

**Degrade, never block.** Every external dependency can fail. A dead RSS feed, a missing API
key, an exhausted quota or a changed YouTube behaviour must each reduce what the app knows,
never prevent it from working. See
[02-architecture.md](02-architecture.md#error-handling-and-degradation).

**Local means local.** Synchronous refreshes, no queue worker to babysit, SQLite, no services
to start beyond `composer run dev`.

**Prefer the framework.** Laravel's HTTP client, scheduler, validation, storage and Livewire.
No new Composer or npm packages — the whole design fits inside what's already installed.

## Terms of Service stance

Calm Tube is built to stay inside YouTube's Terms of Service. Concretely:

| Rule | How the app complies |
| --- | --- |
| Playback must use the official player | Every video plays in a `youtube-nocookie.com/embed/` iframe driven by the official IFrame Player API. There is no other playback path. |
| No downloading or extracting streams | The app never touches video or audio streams. No `yt-dlp`, no format URLs, no proxying of media. |
| Views must be attributable | The embedded player reports playback to YouTube exactly as it would on youtube.com. Creators get their view. |
| Don't circumvent the platform | An "Open on YouTube" link is on every watch page. Comments, likes and subscribing all happen on YouTube, by design. |
| Use public/official data sources | Metadata comes from each channel's public RSS feed, the official YouTube Data API v3 with a personal API key, and YouTube's public oEmbed endpoint for mixes added to the music page. |

### The two grey areas, stated plainly

Two mechanisms read a public YouTube page rather than an API:

1. **Shorts detection** issues one no-redirect `HEAD` to `https://www.youtube.com/shorts/{id}`
   and looks only at the HTTP status. The API has no "is a Short" field, so there is no
   official alternative. No page content is parsed or stored.
2. **Legacy `/c/` URL resolution** (not built; parked in [07-open-questions.md](07-open-questions.md)) would read the `<link rel="alternate">`
   RSS tag from a channel page. There is an official path for `@handles` and `/channel/` URLs;
   this only covers old custom URLs, and the app's fallback is simply to ask you to paste a
   different form of the URL instead.

Both are low-volume, cached permanently after one success, and read metadata only. Neither
scrapes content, harvests data at scale, or bypasses anything. If either ever breaks, the app
degrades to a documented fallback rather than failing.

## Why local-only, and why auth anyway

The app runs under Herd on `localhost`. Authentication is not strictly required — but the
Livewire starter kit already ships Fortify, users, passkeys and 2FA, so the cost of keeping it
is a `auth` middleware group and nothing else. It protects the app if the Herd site is ever
reachable from the LAN, and removing it would be a bigger teardown than keeping it.

Registration is used once to create the single account, then disabled. See
[04-routes-and-ui.md](04-routes-and-ui.md#authentication).

## Where to go next

| Document | Covers |
| --- | --- |
| [02-architecture.md](02-architecture.md) | Components, services, refresh data flow, error handling |
| [03-data-model.md](03-data-model.md) | Tables, columns, indexes, migrations |
| [04-routes-and-ui.md](04-routes-and-ui.md) | Routes, screens, wireframes, every UI state |
| [05-youtube-integration.md](05-youtube-integration.md) | RSS, Data API, quota, durations, Shorts, embed, IFrame API |
| [06-implementation-plan.md](06-implementation-plan.md) | Ordered phases, acceptance criteria, testing strategy |
| [07-open-questions.md](07-open-questions.md) | Unresolved and deliberately deferred |
