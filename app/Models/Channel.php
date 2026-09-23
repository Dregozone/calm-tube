<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ChannelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

/**
 * A YouTube channel you have chosen to follow.
 *
 * @property int $id
 * @property string $youtube_channel_id
 * @property string $title
 * @property string|null $custom_name
 * @property string|null $handle
 * @property string|null $avatar_url
 * @property string|null $avatar_path
 * @property string|null $uploads_playlist_id
 * @property bool $is_enabled
 * @property float|null $playback_rate
 * @property int|null $sample_limit
 * @property string|null $feed_etag
 * @property string|null $feed_last_modified
 * @property CarbonImmutable|null $last_refreshed_at
 * @property string|null $last_refresh_error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read string $display_name
 * @property-read float $effective_playback_rate
 */
#[Fillable([
    'youtube_channel_id',
    'title',
    'custom_name',
    'handle',
    'avatar_url',
    'avatar_path',
    'uploads_playlist_id',
    'is_enabled',
    'playback_rate',
    'sample_limit',
    'feed_etag',
    'feed_last_modified',
    'last_refreshed_at',
    'last_refresh_error',
])]
class Channel extends Model
{
    /** @use HasFactory<ChannelFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (Channel $channel): void {
            // The videos themselves go by database cascade, which fires no
            // model events, so their archived images are removed here.
            $channel->videos()->whereNotNull('thumbnail_path')->each(
                fn (Video $video) => $video->deleteArchivedThumbnail()
            );

            if ($channel->avatar_path !== null) {
                Storage::disk((string) config('calm-tube.images.disk'))->delete($channel->avatar_path);
            }
        });
    }

    /**
     * Channel ids are YouTube's, so URLs map one to one with youtube.com.
     */
    public function getRouteKeyName(): string
    {
        return 'youtube_channel_id';
    }

    /** @return HasMany<Video, $this> */
    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    /** @return HasMany<RefreshRun, $this> */
    public function refreshRuns(): HasMany
    {
        return $this->hasMany(RefreshRun::class);
    }

    /** @return HasOne<Video, $this> */
    public function latestVideo(): HasOne
    {
        return $this->hasOne(Video::class)->latestOfMany('published_at');
    }

    /**
     * Disabled channels are not refreshed and do not appear in the feed.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeEnabled(Builder $query): void
    {
        $query->where('is_enabled', true);
    }

    /**
     * Your own name for the channel, falling back to the one YouTube gives it.
     *
     * @return Attribute<string, never>
     */
    protected function displayName(): Attribute
    {
        return Attribute::get(fn (): string => $this->custom_name ?? $this->title);
    }

    /**
     * The speed every video on this channel plays at, normal unless you have
     * chosen otherwise. A rate that is no longer offered is ignored rather
     * than passed to the player, which would refuse it anyway.
     *
     * @return Attribute<float, never>
     */
    protected function effectivePlaybackRate(): Attribute
    {
        return Attribute::get(function (): float {
            /** @var list<float> $allowed */
            $allowed = config('calm-tube.player.playback_rates');

            return $this->playback_rate !== null && in_array($this->playback_rate, $allowed, true)
                ? $this->playback_rate
                : 1.0;
        });
    }

    /**
     * Only some of this channel's uploads reach the feed.
     */
    public function isSampled(): bool
    {
        return $this->sample_limit !== null;
    }

    public function hasRefreshError(): bool
    {
        return $this->last_refresh_error !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'playback_rate' => 'float',
            'sample_limit' => 'integer',
            'last_refreshed_at' => 'datetime',
        ];
    }
}
