<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Http\Middleware;

use Closure;

/**
 * Middleware to refresh current page every XX seconds.
 * Controlled by the GET parameter "refresh". The value must be an integer.
 * To prevent too much request, the minimum is 15 seconds.
 */
class Refresh
{
    private const MAX_SECONDS = 15;

    public function handle($request, Closure $next)
    {
        $response = $next($request);
        if ($request->has('refresh')) {
            $seconds = max(self::MAX_SECONDS, (int)$request->get('refresh'));
            $url = url()->full();
            $response->header('refresh', $seconds . ';url=' . $url);
            $response->header('Cache-Control', 'no-cache, no-store, must-revalidate');
            $response->header('Pragma', 'no-cache');
            $response->header('Expires', '0');
        }
        return $response;
    }
}
