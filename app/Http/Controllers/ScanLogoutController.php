<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Log out from the scan page and come back to the same document, so the next
 * person on a shared phone logs in and lands on it, not on the dashboard.
 */
class ScanLogoutController extends Controller
{
    public function __invoke(Request $request, string $token): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // A guest opening the scan link is sent to the login page, which
        // remembers the link and returns to it after logging in.
        return redirect()->route('pdts.scan', $token);
    }
}
