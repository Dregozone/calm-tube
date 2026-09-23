<?php

namespace App\Models;

use App\Services\YouTube\DurationParser;
use Carbon\CarbonImmutable;
use Database\Factories\MixFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A long music video you put on in the background, played from the music
 * page without ever leaving it.
 *
 * @property int $id
 * @property string $youtube_video_id
 * @property string $title
 * @property string|null $author_name
 * @property string|null $thumbnail_url
 * @property string|null $thumbnail_path
 * @property int|null $duration_seconds
 * @property int|null $resume_seconds
 * @property CarbonImmutable|null $last_played_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read string|null $duration_for_humans
 * @property-read string|null $resume_for_humans
 * @property-read int<0, 100>|null $percent_played
 */
#[Fillable([
    'youtube_video_id',
    'title',
    'author_name',
    'thumbnail_url',
    'thumbnail_path',
    'duration_seconds',
    'resume_seconds',
    'last_played_at',
])]
class Mix extends Model
{
    /** @use HasFactory<MixFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (Mix $mix): void {
            if ($mix->thumbnail_path !== null) {
                Storage::disk((string) config('calm-tube.images.disk'))->delete($mix->thumbnail_path);
            }
        });
    }

    /**
     * Video ids are YouTube's, so a mix maps one to one with its video.
     */
    public function getRouteKeyName(): string
    {
        return 'youtube_video_id';
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function durationForHumans(): Attribute
    {
        return Attribute::get(
            fn (): ?string => app(DurationParser::class)->toHuman($this->duration_seconds)
        );
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function resumeForHumans(): Attribute
    {
        return Attribute::get(
            fn (): ?string => app(DurationParser::class)->toHuman($this->resume_seconds ?? 0)
        );
    }

    /**
     * How far through the mix you are, for the progress bar before it plays.
     *
     * @return Attribute<int<0, 100>|null, never>
     */
    protected function percentPlayed(): Attribute
    {
        return Attribute::get(function (): ?int {
            if ($this->duration_seconds === null || $this->duration_seconds === 0) {
                return null;
            }

            return max(0, min(100, (int) round(($this->resume_seconds ?? 0) / $this->duration_seconds * 100)));
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'resume_seconds' => 'integer',
            'last_played_at' => 'datetime',
        ];
    }
}
