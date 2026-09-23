<?php

namespace App\Enums;

/**
 * What started a refresh: a button in the UI, the scheduler, or simply
 * opening a feed that had gone stale.
 */
enum RefreshTrigger: string
{
    case Manual = 'manual';
    case Scheduled = 'scheduled';
    case Stale = 'stale';
}
