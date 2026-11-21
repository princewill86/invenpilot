<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CommerceAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $role = $request->attributes->get('business')->pivot->role;
        $roles = $request->route('type') === 'sales' ? ['owner', 'administrator', 'staff'] : ['owner', 'administrator'];
        abort_unless(in_array($role, $roles, true), 403);

        return $next($request);
    }
}
