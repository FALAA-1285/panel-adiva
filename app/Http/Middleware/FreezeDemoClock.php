<?php

namespace App\Http\Middleware;

use App\Support\Demo;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Response;

/**
 * Freezes the clock while the request is handled, then restores it before the
 * session and CSRF middleware write their cookies. The demo moment lies in the
 * past, so cookies stamped with it would already be expired in the browser.
 */
class FreezeDemoClock
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Demo::aktif()) {
            return $next($request);
        }

        $previous = Date::getTestNow();
        Demo::bekukanJam();

        try {
            return $next($request);
        } finally {
            Date::setTestNow($previous);
        }
    }
}
