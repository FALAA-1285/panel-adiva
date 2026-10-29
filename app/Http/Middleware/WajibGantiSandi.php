<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Demo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A password older than 90 days is replaced before anything else in the panel opens
 * (BCA CSA butir 23). Only the Ganti kata sandi page and Keluar stay reachable.
 */
class WajibGantiSandi
{
    /** @var list<string> */
    private const BOLEH = ['sandi.ganti', 'sandi.simpan', 'logout'];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // the demo's clock is frozen on one day, so no password ages there
        if ($user instanceof User && ! Demo::aktif() && $user->sandiKedaluwarsa() && ! $request->routeIs(...self::BOLEH)) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Kata sandi perlu diganti.'], 409)
                : redirect()->route('sandi.ganti');
        }

        return $next($request);
    }
}
