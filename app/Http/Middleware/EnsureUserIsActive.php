<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of an account that is no longer active.
 *
 * Checking only at login would let a deactivated Document Controller keep
 * working until their session expired - up to SESSION_LIFETIME later. This
 * runs on every authenticated request, so revoking access takes effect on
 * the user's next click.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user !== null && ! $user->canLogIn()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withErrors(['email' => 'This account is no longer active. Contact your administrator.']);
        }

        return $next($request);
    }
}
