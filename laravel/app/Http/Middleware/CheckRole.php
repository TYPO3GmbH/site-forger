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
        $rolesArray = explode('|', $roles);
        if (!Auth::guest() && $this->checkIfUserHasAtLeastOneRole($rolesArray, Auth::user()->roles)) {
            return $next($request);
        }
        if ($request->ajax()) {
            abort(401);
        } else {
            abort(401);
        }
    }

    protected function checkIfUserHasAtLeastOneRole(array $allowedRoles, Collection $userRoles)
    {
        foreach ($userRoles as $index => $userRole) {
            if (in_array($userRole->name, $allowedRoles, true)) {
                return true;
            }
        }
        return false;
    }
}