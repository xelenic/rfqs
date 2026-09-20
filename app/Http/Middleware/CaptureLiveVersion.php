<?php

namespace App\Http\Middleware;

use App\LiveVersion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notes the data's version before the page is built. The page carries it
 * (see layouts/app.blade.php) and live updates compare against it — read any
 * later than the data, a change made in between would be missed until the
 * next one.
 */
class CaptureLiveVersion
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('live_version', LiveVersion::current());

        return $next($request);
    }
}
