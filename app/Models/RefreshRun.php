<?php

namespace App\Models;

use App\Enums\RefreshStatus;
use App\Enums\RefreshTrigger;
use Carbon\CarbonImmutable;
use Database\Factories\RefreshRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The outcome of one channel refresh, so the UI can show what happened and
 * when without reading the log.
 *
 * This is the only table that is ever pruned: it is operational history, not
 * part of the archive.
 *
 * @property int $id
 * @property int|null $channel_id
 * @property RefreshTrigger $trigger
 * @property RefreshStatus $status
 * @property int $new_videos_count
 * @property string|null $error_message
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property-read Channel|null $channel
 */
#[Fillable([
    'channel_id',
    'trigger',
    'status',
    'new_videos_count',
    'error_message',
    'started_at',
    'finished_at',
])]
class RefreshRun extends Model
{
    /** @use HasFactory<RefreshRunFactory> */
    use HasFactory;

    /**
     * started_at and finished_at already say everything a timestamp would.
     */
    public $timestamps = false;

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger' => RefreshTrigger::class,
            'status' => RefreshStatus::class,
            'new_videos_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
