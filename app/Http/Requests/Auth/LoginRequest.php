<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Validates and performs a login attempt.
 *
 * Attempts are throttled per email-and-IP pair (CLAUDE.md 12). The failure
 * message is identical whether the email is unknown or the password is wrong,
 * so the form cannot be used to enumerate which accounts exist.
 */
class LoginRequest extends FormRequest
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @throws ValidationException
     */
    public function authenticate(AuditLogger $auditLogger): void
    {
        $this->ensureIsNotRateLimited();

        $credentials = $this->only('email', 'password');

        if (! Auth::attempt($credentials, $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);

            // Logged without the password, and with the submitted email so an
            // administrator can see which account is being probed.
            $auditLogger->log(
                AuditLogger::ACTION_LOGIN_FAILED,
                newValues: ['email' => $credentials['email']],
            );

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        $user = Auth::user();

        // Authenticated, but the account has been deactivated. The session is
        // dropped immediately rather than being left for the middleware.
        if ($user !== null && ! $user->canLogIn()) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => 'This account is not active. Contact your administrator.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => sprintf(
                'Too many login attempts. Please try again in %d seconds.',
                $seconds,
            ),
        ]);
    }

    /**
     * Throttle on email *and* IP.
     *
     * On IP alone, one office NAT would lock out a whole site; on email alone,
     * an attacker could lock a known administrator out at will.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(
            Str::lower((string) $this->string('email')).'|'.$this->ip(),
        );
    }
}
