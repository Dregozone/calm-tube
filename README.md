# Calm Tube

A personal app for following a hand-picked set of YouTube channels without ever visiting
youtube.com's homepage, recommendations or sidebar.

One reverse-chronological list of what the channels you chose have published. No algorithm,
no autoplay, no "up next", no Shorts. Single user, runs locally under Herd, never deployed.

Three rules the app is built around, and none of them bend:

1. **Archived metadata is immutable.** A title or thumbnail changed on YouTube after you first
   saw the video never changes here.
2. **Shorts never appear.** Not filtered by default — excluded, with no toggle to show them.
3. **Nothing suggests another video.** The one exception is a single explicit "next unwatched
   from this channel" button at the end of a video, which you click or you don't.

The full design lives in [docs/](docs/), starting with
[the project brief](docs/00-project-brief.md).

---

## Running it

```bash
composer run dev
```

That is `php artisan dev`, which runs the server and Vite together. Under Herd the app is
already served at `http://calm-tube.test`, so `npm run dev` on its own is usually enough.

**Nothing needs to be running in the background.** There is no queue worker and no cron to
remember:

- Refreshes are **synchronous**. `RefreshChannel` is not a `ShouldQueue`, so the `jobs` table is
  never written to and there is nothing to drain.
- Opening a feed that has not been refreshed for **6 hours refreshes it by itself**, after the
  page has rendered. What it finds is announced — *"12 new videos arrived while you were away"* —
  rather than slid into the grid you are reading.
- The **Refresh all** button always works, and holds nothing back.

The only thing you lose without a scheduler is refreshing while the app is closed, and the
nightly housekeeping. If you want those, run it in a second terminal:

```bash
php artisan schedule:work
```

`CALM_TUBE_AUTO_REFRESH_HOURS=0` turns the automatic refresh off.

---

## Setup

```bash
composer setup
```

That installs both sets of dependencies, writes `.env`, generates a key, migrates and builds.

Register an account at `/register`, then add channels at `/channels`. Registration stays open
so the account can be recreated after a `migrate:fresh`.

### The YouTube API key

Add one to `.env`:

```
YOUTUBE_API_KEY=
```

The app boots and runs without it, but a lot is unavailable:

| Without a key | With a key |
| --- | --- |
| Durations are blank | Durations, live status and Shorts detection work |
| A pasted `@handle` can't be resolved — only a raw `UC…` channel ID | Any handle, URL or video link resolves |
| **Discovery fails entirely while YouTube's RSS feed is down** | Refreshes fall back to the uploads playlist |

That last row matters right now: YouTube's `/feeds/videos.xml` has been answering 404
intermittently since late 2025, so refreshes are currently running on the uploads playlist,
which costs one quota unit per channel. Roughly 600 units a day at hourly refreshes, against
a 10,000 unit allowance.

---

## Commands

| Command | What it does |
| --- | --- |
| `php artisan calm:refresh` | Fetch new uploads from every enabled channel |
| `php artisan calm:backfill` | Recover older uploads the RSS window can no longer reach |
| `php artisan calm:enrich` | Fill in durations and live status for videos still missing them |
| `php artisan calm:archive` | Download thumbnails and avatars not yet stored locally |
| `php artisan calm:sample` | Re-apply channel sample limits across their whole history |
| `php artisan calm:prune-runs` | Trim refresh history older than 30 days |

`calm:prune-runs` is the only thing in the app that deletes on a schedule, and it only touches
operational history. Videos and channels are kept forever.

Scheduled: refresh hourly, enrich at 04:00, archive at 04:30, prune Mondays at 05:00.

---

## Keyboard

| Key | Does |
| --- | --- |
| `g` then `f` | Feed |
| `g` then `c` | Channels |
| `/` | Focus this page's one text control — the channel filter, or the add form |
| `Esc` | Close a modal |

There is deliberately no `j`/`k` card-by-card navigation. That is a scrolling-speed feature,
and speed is not the goal.

---

## Working through a backlog

The feed opens on **Unwatched**, and remembers whichever you last chose after that.

Two bulk actions, because clearing fifty videos one at a time is not a feature:

- **Mark page watched** on the feed — clears the twenty-four in front of you.
- **Mark all as watched** on a channel page — clears that channel entirely.

Both offer **Undo**, and neither deletes or hides anything. The videos stay in your archive,
still reachable from the channel page; they just stop waiting for you.

### Channels that publish too much

Some channels post one real video a day and a dozen clips cut out of it. On the channel's
**Edit** screen, *"How much of this channel reaches your feed"* keeps only the longest few of
each day; everything else stays on the channel page under a **Set aside** tab.

Length is the whole rule, which is why it is per channel and off by default — it works on a
clip farm and is worthless on a channel whose uploads are all a similar length.

Long videos remember where you stopped. Reopening one offers "Resume from 18:42" rather than
seeking there on its own, and the card shows a thin bar across the thumbnail.

---

## Development

```bash
php artisan test --compact
composer quality
```

`composer quality` runs Rector, Pint and PHPStan at level 7. Both should be clean before a
commit.

The test suite blocks the network (`Http::preventStrayRequests()`), so a forgotten fake fails
loudly instead of reaching YouTube. Fixtures live in `tests/Fixtures/youtube/`, with one fake
helper per endpoint.
