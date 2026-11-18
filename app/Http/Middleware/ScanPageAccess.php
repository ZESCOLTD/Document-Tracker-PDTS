<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who may open a QR scan page. With pdts.scan_requires_login on, guests are
 * sent to the login page (and come back afterwards). With it off, guests may
 * track and confirm a pending pickup or receipt; the page itself limits them.
 */
class ScanPageAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user && config('pdts.scan_requires_login')) {
            return redirect()->guest(route('login'));
        }

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        return $next($request);
    }
}
