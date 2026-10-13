<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ReadingListCourseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A course on Personal Assistant's reading list (category "Course"), as it
 * last answered.
 *
 * Replaced wholesale on every refresh, like ReadingListItem. Courses are
 * usually ordinary websites, so nothing here is archived or played.
 *
 * @property int $id
 * @property int $reading_list_id
 * @property string $title
 * @property string|null $url
 * @property string|null $notes
 * @property int $important
 * @property int $urgent
 * @property int|null $estimated_minutes
 * @property int $percent_complete
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read int $priority
 * @property-read string|null $duration_for_humans
 */
#[Fillable([
    'reading_list_id',
    'title',
    'url',
    'notes',
    'important',
    'urgent',
    'estimated_minutes',
    'percent_complete',
    'position',
])]
class ReadingListCourse extends Model
{
    /** @use HasFactory<ReadingListCourseFactory> */
    use HasFactory;

    /**
     * The reading list's category for a course, compared case-insensitively
     * and without surrounding spaces.
     */
    public const string CATEGORY = 'Course';

    public static function isCourse(?string $category): bool
    {
        return $category !== null && strcasecmp(trim($category), self::CATEGORY) === 0;
    }

    /**
     * "6 h 30 m", "6 h" or "45 m".
     */
    public static function formatMinutes(?int $minutes): ?string
    {
        if ($minutes === null || $minutes <= 0) {
            return null;
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return match (true) {
            $hours === 0 => "{$rest} m",
            $rest === 0 => "{$hours} h",
            default => "{$hours} h {$rest} m",
        };
    }

    /**
     * Done as far as Calm Tube is concerned: the same mark as a finished
     * reading list video.
     */
    public function isFinished(): bool
    {
        return $this->percent_complete >= (int) config('calm-tube.reading_list.done_percent');
    }

    public function isInProgress(): bool
    {
        return $this->percent_complete > 0 && ! $this->isFinished();
    }

    /**
     * Personal Assistant's priority: important × urgent.
     *
     * @return Attribute<int, never>
     */
    protected function priority(): Attribute
    {
        return Attribute::get(fn (): int => $this->important * $this->urgent);
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function durationForHumans(): Attribute
    {
        return Attribute::get(fn (): ?string => self::formatMinutes($this->estimated_minutes));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reading_list_id' => 'integer',
            'important' => 'integer',
            'urgent' => 'integer',
            'estimated_minutes' => 'integer',
            'percent_complete' => 'integer',
            'position' => 'integer',
        ];
    }
}
