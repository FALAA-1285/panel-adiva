<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A staff member turned off on Tim & akses is signed out on their next request, however
 * they were signed in: a session kept somewhere else, or "Ingat perangkat ini" (Audit AUD-004).
 */
class TolakAkunNonaktif
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->nonaktif_at !== null) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'Akun ini sudah dinonaktifkan. Hubungi supervisor.']);
        }

        return $next($request);
    }
}
