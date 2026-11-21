<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BusinessAccess
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        foreach (['business', 'record', 'member'] as $parameter) {
            $value = $request->route($parameter);
            abort_if($value !== null && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false, 404);
        }

        // Resolve the business through the caller's memberships before any record lookup.
        $business = $request->attributes->get('business') ?? $request->user()->businesses()->findOrFail($request->route('business'));
        abort_if($business->suspended_at !== null, 403, 'This business has been suspended.');
        abort_if($roles !== [] && ! in_array($business->pivot->role, $roles, true), 403);
        $request->attributes->set('business', $business);

        return $next($request);
    }
}
