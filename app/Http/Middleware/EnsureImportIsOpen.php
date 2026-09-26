<?php

namespace App\Http\Middleware;

use App\Http\Controllers\ImportController;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the one-time import endpoint shut unless it is deliberately open.
 *
 * Open means: a long enough token is configured, the request carries it, and
 * no import has finished yet. Anything else is a 404, so a closed endpoint is
 * indistinguishable from one that was never there.
 */
class EnsureImportIsOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('calm-tube.import.token');

        abort_if(strlen($token) < (int) config('calm-tube.import.minimum_token_length'), 404);
        abort_if(Cache::has(ImportController::COMPLETED_KEY), 404);
        abort_unless(hash_equals($token, (string) $request->bearerToken()), 404);

        return $next($request);
    }
}
