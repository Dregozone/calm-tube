<?php

namespace App\Models;

use App\Enums\LiveStatus;
use App\Services\YouTube\DurationParser;
use Carbon\CarbonImmutable;
use Database\Factories\VideoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One upload from a followed channel.
 *
 * The title, description and archived thumbnail are written once at first
 * ingest and never updated, so a video retitled or re-thumbnailed on YouTube
 * keeps the form it had when you first saw it.
 *
 * @property int $id
 * @property int $channel_id
 * @property string $youtube_video_id
 * @property string $title
 * @property string|null $description
 * @property string|null $thumbnail_url
 * @property string|null $thumbnail_path
 * @property CarbonImmutable $published_at
 * @property int|null $duration_seconds
 * @property int|null $resume_seconds
 * @property bool|null $is_short
 * @property LiveStatus $live_status
 * @property CarbonImmutable|null $scheduled_start_at
 * @property CarbonImmutable|null $watched_at
 * @property CarbonImmutable|null $hidden_at
 * @property CarbonImmutable|null $sampled_out_at
 * @property string|null $pick_reason
 * @property CarbonImmutable|null $snoozed_at
 * @property CarbonImmutable|null $unavailable_at
 * @property CarbonImmutable|null $enriched_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read string|null $duration_for_humans
 * @property-read int|null $watching_seconds
 * @property-read string|null $watching_time_for_humans
 * @property-read string|null $resume_for_humans
 * @property-read int<0, 100>|null $percent_watched
 * @property-read Channel $channel
 */
#[Fillable([
    'channel_id',
    'youtube_video_id',
    'title',
    'description',
    'thumbnail_url',
    'thumbnail_path',
    'published_at',
    'duration_seconds',
    'resume_seconds',
    'is_short',
    'live_status',
    'scheduled_start_at',
    'watched_at',
    'hidden_at',
    'sampled_out_at',
    'pick_reason',
    'snoozed_at',
    'unavailable_at',
    'enriched_at',
])]
class Video extends Model
{
    /** @use HasFactory<VideoFactory> */
    use HasFactory;

    /** Below this, you may as well start again. */
    private const int RESUME_FLOOR = 15;

    /** Within this of the end, there is nothing left to resume. */
    private const int RESUME_CEILING = 15;

    protected static function booted(): void
    {
        // Stamped here rather than by each caller, so every way a video can
        // arrive (refresh, overflow recovery, backfill) respects a snooze.
        static::creating(function (Video $video): void {
            if ($video->snoozed_at === null && $video->channel->wasSnoozedAt($video->published_at)) {
                $video->snoozed_at = now();
            }
        });

        static::deleting(function (Video $video): void {
            $video->deleteArchivedThumbnail();
        });
    }

    /**
     * The archived image outlives YouTube, so it only goes when the row does.
     */
    public function deleteArchivedThumbnail(): void
    {
        if ($this->thumbnail_path === null) {
            return;
        }

        Storage::disk((string) config('calm-tube.images.disk'))->delete($this->thumbnail_path);
    }

    /**
     * Video ids are YouTube's, so /watch/{id} maps one to one with youtube.com.
     */
    public function getRouteKeyName(): string
    {
        return 'youtube_video_id';
    }

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * The single definition of what is fit to be shown anywhere.
     *
     * A null is_short means detection could not decide, and those are shown:
     * a leaked Short is an annoyance, a silently swallowed video is not.
     *
     * Says nothing about the channel. A channel page shows what it archived
     * whether or not you still follow it; the feed is the stricter question.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeViewable(Builder $query): void
    {
        $query->whereNull('hidden_at')
            // Published while its channel was snoozed: kept, never shown.
            ->whereNull('snoozed_at')
            ->whereNull('unavailable_at')
            ->where('live_status', LiveStatus::None)
            ->where(fn (Builder $shortsQuery): Builder => $shortsQuery
                ->where('is_short', false)
                ->orWhereNull('is_short'));
    }

    /**
     * The single definition of what belongs in the feed: viewable, and from a
     * channel you are still following.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeInFeed(Builder $query): void
    {
        $query->viewable()
            // Set aside by a channel's sample limit. Deliberately not part of
            // viewable(): the channel page must still show what it holds.
            ->whereNull('sampled_out_at')
            ->whereHas('channel', fn (Builder $channelQuery): Builder => $channelQuery
                ->where('is_enabled', true));
    }

    /**
     * Held back from the feed by the channel's sample limit.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeSetAside(Builder $query): void
    {
        $query->whereNotNull('sampled_out_at');
    }

    public function isSetAside(): bool
    {
        return $this->sampled_out_at !== null;
    }

    /**
     * You have watched this, or started to.
     *
     * Enough for a channel's sample limit to leave it alone: a rule that
     * recalculates a day can otherwise take back a video you had already
     * opened, which is the one thing it must never do.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeTouched(Builder $query): void
    {
        $query->where(fn (Builder $either): Builder => $either
            ->whereNotNull('watched_at')
            ->orWhereNotNull('resume_seconds'));
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeUntouched(Builder $query): void
    {
        $query->whereNull('watched_at')->whereNull('resume_seconds');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeUnwatched(Builder $query): void
    {
        $query->whereNull('watched_at');
    }

    public function isWatched(): bool
    {
        return $this->watched_at !== null;
    }

    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
    }

    public function isUnavailable(): bool
    {
        return $this->unavailable_at !== null;
    }

    /**
     * Derived rather than stored, because it is purely a presentation concern.
     *
     * @return Attribute<string|null, never>
     */
    protected function durationForHumans(): Attribute
    {
        return Attribute::get(
            fn (): ?string => app(DurationParser::class)->toHuman($this->duration_seconds)
        );
    }

    /**
     * How long the video will actually take you, for the feed: its channel's
     * speed applied, and its outro plug left off. The watch page shows the
     * real duration; this is only an at-a-glance answer to "have I got time?"
     *
     * Worked out on every read rather than stored, so changing a channel's
     * speed changes every one of its videos at once.
     *
     * @return Attribute<int|null, never>
     */
    protected function watchingSeconds(): Attribute
    {
        return Attribute::get(function (): ?int {
            if ($this->duration_seconds === null) {
                return null;
            }

            $outro = $this->channel->outro_seconds ?? 0;
            $seconds = $outro > 0 && $outro < $this->duration_seconds
                ? $this->duration_seconds - $outro
                : $this->duration_seconds;

            return (int) round($seconds / $this->channel->effective_playback_rate);
        });
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function watchingTimeForHumans(): Attribute
    {
        return Attribute::get(
            fn (): ?string => app(DurationParser::class)->toHuman($this->watching_seconds)
        );
    }

    /**
     * Where you got to, as a timestamp you can read back.
     *
     * @return Attribute<string|null, never>
     */
    protected function resumeForHumans(): Attribute
    {
        return Attribute::get(
            fn (): ?string => app(DurationParser::class)->toHuman($this->resume_seconds)
        );
    }

    /**
     * How far through the video you are, for the bar across the thumbnail.
     *
     * Clamped to its track: the player reports a fraction past the duration
     * YouTube gave us often enough to matter, and a bar wider than its track,
     * or narrower than nothing, looks broken.
     *
     * @return Attribute<int<0, 100>|null, never>
     */
    protected function percentWatched(): Attribute
    {
        return Attribute::get(function (): ?int {
            if ($this->resume_seconds === null || $this->duration_seconds === null) {
                return null;
            }

            if ($this->duration_seconds === 0) {
                return null;
            }

            return max(0, min(100, (int) round($this->resume_seconds / $this->duration_seconds * 100)));
        });
    }

    /**
     * Far enough in that picking up where you left off beats starting again,
     * and not so near the end that there is nothing left to watch.
     */
    public function isResumable(): bool
    {
        if ($this->resume_seconds === null || $this->resume_seconds < self::RESUME_FLOOR) {
            return false;
        }

        return $this->duration_seconds === null
            || $this->resume_seconds < $this->duration_seconds - self::RESUME_CEILING;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'scheduled_start_at' => 'datetime',
            'watched_at' => 'datetime',
            'hidden_at' => 'datetime',
            'sampled_out_at' => 'datetime',
            'snoozed_at' => 'datetime',
            'unavailable_at' => 'datetime',
            'enriched_at' => 'datetime',
            'duration_seconds' => 'integer',
            'is_short' => 'boolean',
            'live_status' => LiveStatus::class,
        ];
    }
}
