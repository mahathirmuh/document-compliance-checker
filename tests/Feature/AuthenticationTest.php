<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_login_screen_renders(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Sign in');
    }

    #[Test]
    public function a_user_can_sign_in(): void
    {
        $user = User::factory()->admin()->create(['password' => Hash::make('correct-horse')]);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'correct-horse',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function signing_in_records_the_time_and_an_audit_entry(): void
    {
        $user = User::factory()->admin()->create(['password' => Hash::make('correct-horse')]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'correct-horse']);

        $this->assertNotNull($user->refresh()->last_login_at);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => AuditLogger::ACTION_LOGIN,
        ]);
    }

    #[Test]
    public function a_wrong_password_is_rejected_and_audited(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-horse')]);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogger::ACTION_LOGIN_FAILED]);
    }

    #[Test]
    public function an_unknown_email_gives_the_same_message_as_a_wrong_password(): void
    {
        // Identical wording so the form cannot be used to enumerate accounts.
        $user = User::factory()->create(['password' => Hash::make('correct-horse')]);

        $unknown = $this->post(route('login.store'), [
            'email' => 'nobody@example.com',
            'password' => 'whatever',
        ]);

        $wrongPassword = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong',
        ]);

        $this->assertSame(
            $this->firstEmailError($unknown),
            $this->firstEmailError($wrongPassword),
        );
    }

    #[Test]
    public function an_inactive_account_cannot_sign_in(): void
    {
        $user = User::factory()->inactive()->create(['password' => Hash::make('correct-horse')]);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'correct-horse',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    #[Test]
    public function repeated_failures_are_throttled(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-horse')]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong']);
        }

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'correct-horse',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString(
            'Too many login attempts',
            (string) $response->getSession()->get('errors')->first('email'),
        );
        $this->assertGuest();
    }

    #[Test]
    public function a_user_can_sign_out(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => AuditLogger::ACTION_LOGOUT,
        ]);
    }

    #[Test]
    public function no_password_is_ever_written_to_the_audit_trail(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-horse')]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'hunter2']);

        foreach (AuditLog::all() as $entry) {
            $serialised = json_encode([$entry->old_values, $entry->new_values]);

            $this->assertStringNotContainsString('hunter2', (string) $serialised);
        }
    }

    /**
     * Pull the first `email` error out of a response's flashed error bag.
     *
     * The session stores a ViewErrorBag, so this normalises the two shapes
     * the framework can hand back rather than assuming one.
     */
    private function firstEmailError(TestResponse $response): string
    {
        $errors = $response->getSession()->get('errors');

        if ($errors instanceof ViewErrorBag) {
            return (string) $errors->first('email');
        }

        return (string) (data_get($errors, 'email.0') ?? '');
    }
}
