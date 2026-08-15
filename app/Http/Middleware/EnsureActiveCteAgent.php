<?php

namespace App\Http\Middleware;

use App\Models\CteAgent;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveCteAgent
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $agent = $request->user();

        if (! $agent instanceof CteAgent || ! $agent->is_active) {
            abort(Response::HTTP_FORBIDDEN, 'Inactive or invalid CT-e agent.');
        }

        return $next($request);
    }
}
