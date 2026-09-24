<?php

namespace App\Enums;

/**
 * What a channel's sample limit counts against.
 *
 * A day keeps the longest few of each day's uploads as they arrive. A week
 * holds the uploads back until the week is over, then picks the best few of
 * the whole week against what you said you want from the channel.
 */
enum SamplePeriod: string
{
    case Day = 'day';
    case Week = 'week';
}
