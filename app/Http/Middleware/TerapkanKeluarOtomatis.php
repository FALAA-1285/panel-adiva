<?php

namespace App\Http\Middleware;

use App\Support\PengaturanKeamanan;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Keluar otomatis setelah tidak aktif" (Tim & akses) sets the session lifetime.
 * Runs before StartSession, which reads it to expire idle sessions.
 */
class TerapkanKeluarOtomatis
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        config(['session.lifetime' => PengaturanKeamanan::keluarOtomatisJam() * 60]);

        return $next($request);
    }
}
