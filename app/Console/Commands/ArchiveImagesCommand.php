<?php

namespace App\Console\Commands;

use App\Enums\LiveStatus;
use App\Models\Channel;
use App\Models\Video;
use App\Services\YouTube\ImageArchiver;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Downloads any thumbnail or avatar not yet archived.
 *
 * Catches up a library built before archiving existed, and retries whatever
 * failed to download last time, so a dropped connection heals itself instead
 * of leaving one card hotlinking forever.
 *
 * Shorts are skipped: they never reach the feed, so their images are never
 * shown, and on a Shorts heavy channel that is most of the downloads.
 *
 * --repair first forgets every path whose image is no longer stored, as after
 * restoring a backup made without archived_images, so those are downloaded
 * again too. Until then each card falls back to the original URL.
 */
class ArchiveImagesCommand extends Command
{
    protected $signature = 'calm:archive
        {--limit=0 : Stop after this many images}
        {--repair : Also re-download images whose stored copy is missing}';

    protected $description = 'Archive thumbnails and avatars that are not stored yet';

    public function handle(ImageArchiver $archiver): int
    {
        $limit = max((int) $this->option('limit'), 0);
        $archived = 0;
        $failed = 0;

        if ($this->option('repair')) {
            $this->forgetMissingImages();
        }

        foreach ($this->channels() as $channel) {
            $path = $archiver->archiveAvatar($channel);

            if ($path === null) {
                // Counted, not swallowed: avatars come from a different host
                // to thumbnails, so they can fail on their own.
                $failed++;

                continue;
            }

            $channel->forceFill(['avatar_path' => $path])->save();
            $archived++;
        }

        $videos = $this->videos();
        $this->line("{$videos->count()} ".Str::plural('thumbnail', $videos->count()).' to archive.');

        foreach ($videos as $video) {
            if ($limit > 0 && $archived >= $limit) {
                break;
            }

            $path = $archiver->archiveThumbnail($video);

            if ($path === null) {
                $failed++;

                continue;
            }

            $video->forceFill(['thumbnail_path' => $path])->save();
            $archived++;
        }

        $this->newLine();
        $this->info(sprintf(
            'Archived %d %s.%s',
            $archived,
            Str::plural('image', $archived),
            $failed === 0 ? '' : " {$failed} could not be downloaded and will be retried."
        ));

        return self::SUCCESS;
    }

    /**
     * Clears each avatar and thumbnail path that points at nothing, so the
     * usual queries below pick them up again.
     */
    private function forgetMissingImages(): void
    {
        $disk = Storage::disk((string) config('calm-tube.images.disk'));
        $forgotten = 0;

        foreach (Channel::query()->whereNotNull('avatar_path')->get() as $channel) {
            if (! $disk->exists($channel->avatar_path)) {
                $channel->forceFill(['avatar_path' => null])->save();
                $forgotten++;
            }
        }

        foreach (Video::query()->whereNotNull('thumbnail_path')->lazyById() as $video) {
            if (! $disk->exists($video->thumbnail_path)) {
                $video->forceFill(['thumbnail_path' => null])->save();
                $forgotten++;
            }
        }

        $this->line("{$forgotten} ".Str::plural('image', $forgotten).' missing from the archive.');
    }

    /**
     * @return Collection<int, Video>
     */
    private function videos()
    {
        return Video::query()
            ->whereNull('thumbnail_path')
            ->whereNotNull('thumbnail_url')
            ->whereNull('unavailable_at')
            ->where('live_status', LiveStatus::None)
            ->where(fn ($query) => $query->where('is_short', false)->orWhereNull('is_short'))
            ->orderByDesc('published_at')
            ->get();
    }

    /**
     * @return Collection<int, Channel>
     */
    private function channels()
    {
        return Channel::query()
            ->whereNull('avatar_path')
            ->whereNotNull('avatar_url')
            ->get();
    }
}
