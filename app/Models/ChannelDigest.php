<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ChannelDigestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The decision about one closed week of a weekly channel: which of its
 * uploads reached the feed, and how they were chosen.
 *
 * @property int $id
 * @property int $channel_id
 * @property CarbonImmutable $week_starts_on
 * @property string $method
 * @property string|null $model
 * @property list<int> $picked_video_ids
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'channel_id',
    'week_starts_on',
    'method',
    'model',
    'picked_video_ids',
])]
class ChannelDigest extends Model
{
    /** Chosen by the model against your note and history. */
    public const string METHOD_AI = 'ai';

    /** The model could not answer in time, so the longest were kept. */
    public const string METHOD_LENGTH = 'length';

    /** Nothing left to pick: the week's budget went on videos you opened. */
    public const string METHOD_BUDGET = 'budget';

    /** @use HasFactory<ChannelDigestFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Channel, $this>
     */
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
            'week_starts_on' => 'immutable_date',
            'picked_video_ids' => 'array',
        ];
    }
}
