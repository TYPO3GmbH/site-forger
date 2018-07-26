<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Http\Middleware;

use Auth;
use Closure;

class CheckRole
{
    /**
     * Handle the incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param Closure $next
     * @param string $roles
     *
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function handle($request, Closure $next, $roles)
    {
        if (!Auth::guest() && Auth::user() !== null && Auth::user()->hasAnyRole(explode('|', $roles))) {
            return $next($request);
        }
        return abort(401);
    }
}
