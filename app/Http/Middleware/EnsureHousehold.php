<?php

namespace App\Http\Middleware;

use App\Support\CurrentHousehold;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureHousehold
{
    public function __construct(private CurrentHousehold $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            $this->current->resolveFor($user);
        }

        return $next($request);
    }
}
