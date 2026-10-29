<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP security headers on every panel response (BCA CSA, butir 14, 15 dan 22).
 *
 * The Content-Security-Policy lets scripts run only from the panel itself or with this
 * response's nonce, which Vite stamps on the tags it writes. Styles allow 'unsafe-inline'
 * because React writes `style` attributes; images allow any https source because product
 * photos and Instagram/Facebook profile pictures live elsewhere.
 */
class HeaderKeamanan
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // before the view renders, so @vite writes the nonce on its tags
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // on a developer's machine a violation is only reported, so a dev tool never breaks the panel
        $response->headers->set(
            app()->isLocal() ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy',
            $this->kebijakan($nonce),
        );

        return $response;
    }

    private function kebijakan(string $nonce): string
    {
        // `npm run dev`: the scripts, styles and hot-reload socket come from Vite's own server
        $vite = '';

        if (File::exists(public_path('hot'))) {
            $asal = rtrim(trim((string) File::get(public_path('hot'))), '/');
            $vite = ' '.$asal.' '.preg_replace('#^http#', 'ws', $asal);
        }

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'{$vite}",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com{$vite}",
            "font-src 'self' https://fonts.gstatic.com data:",
            "img-src 'self' data: blob: https:",
            "media-src 'self' blob:",
            "connect-src 'self'{$vite}",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "base-uri 'self'",
        ]);
    }
}
