<?php

namespace App\Enums;

/**
 * What started a refresh: a button in the UI, or the scheduler.
 */
enum RefreshTrigger: string
{
    case Manual = 'manual';
    case Scheduled = 'scheduled';
}
