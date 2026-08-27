<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureReconciliationV2Enabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('reconciliation.v2_enabled', false), 404);

        return $next($request);
    }
}
