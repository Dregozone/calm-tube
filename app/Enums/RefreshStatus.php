<?php

namespace App\Enums;

/**
 * The outcome of one channel refresh.
 *
 * Degraded means the RSS half worked and the API half did not, so videos were
 * ingested but have no duration yet.
 */
enum RefreshStatus: string
{
    case Running = 'running';
    case Ok = 'ok';
    case Degraded = 'degraded';
    case Failed = 'failed';
}
