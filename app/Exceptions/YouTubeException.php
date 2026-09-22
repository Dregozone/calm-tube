<?php

namespace App\Exceptions;

use Exception;

/**
 * Base for everything that can go wrong talking to YouTube.
 *
 * Callers catch this to degrade gracefully rather than fail, so a refresh
 * keeps whatever it managed to fetch.
 */
abstract class YouTubeException extends Exception {}
