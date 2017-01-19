<?php

namespace App\Http\Middleware;

use Auth;
use Closure;
use Illuminate\Database\Eloquent\Collection;

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
        if (!Auth::guest() && Auth::user()->hasAnyRole(explode('|', $roles))) {
            return $next($request);
        }
        abort(401);
    }
}