<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ReadingListItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A YouTube video on Personal Assistant's reading list, as it last answered.
 *
 * Replaced wholesale on every refresh. The video it points to is archived in
 * the videos table, which is where its title, thumbnail and duration live.
 *
 * @property int $id
 * @property int $reading_list_id
 * @property string $youtube_video_id
 * @property string $title
 * @property string $url
 * @property string|null $category
 * @property int $percent_complete
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Video|null $video
 */
#[Fillable([
    'reading_list_id',
    'youtube_video_id',
    'title',
    'url',
    'category',
    'percent_complete',
    'position',
])]
class ReadingListItem extends Model
{
    /** @use HasFactory<ReadingListItemFactory> */
    use HasFactory;

    /** @return BelongsTo<Video, $this> */
    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class, 'youtube_video_id', 'youtube_video_id');
    }

    /**
     * Still to watch: short of the point where it counts as done.
     *
     * @param  Builder<$this>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('percent_complete', '<', (int) config('calm-tube.reading_list.done_percent'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reading_list_id' => 'integer',
            'percent_complete' => 'integer',
            'position' => 'integer',
        ];
    }
}
