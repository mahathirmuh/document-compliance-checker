<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Session login and logout.
 *
 * There is no self-registration: accounts are created by a SUPER_ADMIN. An
 * internal Document Control system has a known, finite user list, and an open
 * sign-up form would be a way in rather than a feature.
 */
class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        $request->authenticate($auditLogger);

        // Rotates the session id so a fixation attempt cannot survive login.
        $request->session()->regenerate();

        $user = $request->user();

        $user?->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $auditLogger->log(AuditLogger::ACTION_LOGIN, $user);

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $auditLogger->log(AuditLogger::ACTION_LOGOUT, $request->user());

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
