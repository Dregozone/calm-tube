<?php

namespace App\Enums;

/**
 * What a pasted string turned out to be, before anything is looked up.
 */
enum ChannelInputType: string
{
    case ChannelId = 'channel_id';
    case Handle = 'handle';
    case Username = 'username';
    case VideoId = 'video_id';

    /** An old /c/ custom URL, which the Data API cannot resolve. */
    case LegacyCustom = 'legacy_custom';
}
