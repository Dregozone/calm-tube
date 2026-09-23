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
 * @property bool|null $is_short
 * @property LiveStatus $live_status
 * @property CarbonImmutable|null $scheduled_start_at
 * @property CarbonImmutable|null $watched_at
 * @property CarbonImmutable|null $hidden_at
 * @property CarbonImmutable|null $unavailable_at
 * @property CarbonImmutable|null $enriched_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read string|null $duration_for_humans
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
    'is_short',
    'live_status',
    'scheduled_start_at',
    'watched_at',
    'hidden_at',
    'unavailable_at',
    'enriched_at',
])]
class Video extends Model
{
    /** @use HasFactory<VideoFactory> */
    use HasFactory;

    protected static function booted(): void
    {
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
            ->whereHas('channel', fn (Builder $channelQuery): Builder => $channelQuery
                ->where('is_enabled', true));
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'scheduled_start_at' => 'datetime',
            'watched_at' => 'datetime',
            'hidden_at' => 'datetime',
            'unavailable_at' => 'datetime',
            'enriched_at' => 'datetime',
            'duration_seconds' => 'integer',
            'is_short' => 'boolean',
            'live_status' => LiveStatus::class,
        ];
    }
}
