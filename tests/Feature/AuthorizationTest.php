<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Document;
use App\Models\DocumentSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Role boundaries (CLAUDE.md 8.1, 12, 22).
 *
 * Written from the outside in: what a given role can actually reach over
 * HTTP, rather than only whether the policy method returns the right boolean.
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function guests_are_redirected_to_the_login_screen(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('documents.index'))->assertRedirect(route('login'));
        $this->get(route('sources.index'))->assertRedirect(route('login'));
    }

    #[Test]
    #[DataProvider('everyRole')]
    public function every_active_role_can_read_the_dashboard_and_document_list(UserRole $role): void
    {
        $user = User::factory()->role($role)->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->actingAs($user)->get(route('documents.index'))->assertOk();
    }

    #[Test]
    public function a_viewer_cannot_reach_source_management(): void
    {
        $viewer = User::factory()->viewer()->create();

        $this->actingAs($viewer)->get(route('sources.index'))->assertForbidden();
        $this->actingAs($viewer)->get(route('sources.create'))->assertForbidden();
    }

    #[Test]
    public function a_document_controller_cannot_reach_source_management(): void
    {
        // Controlling documents is not the same authority as deciding which
        // folders the server reads.
        $controller = User::factory()->documentController()->create();

        $this->actingAs($controller)->get(route('sources.index'))->assertForbidden();
    }

    #[Test]
    public function an_admin_can_reach_source_management(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('sources.index'))->assertOk();
        $this->actingAs($admin)->get(route('sources.create'))->assertOk();
    }

    #[Test]
    public function a_viewer_cannot_reach_the_upload_form(): void
    {
        $this->actingAs(User::factory()->viewer()->create())
            ->get(route('documents.upload'))
            ->assertForbidden();
    }

    #[Test]
    public function a_document_controller_can_reach_the_upload_form(): void
    {
        $this->actingAs(User::factory()->documentController()->create())
            ->get(route('documents.upload'))
            ->assertOk();
    }

    #[Test]
    public function only_admins_and_above_can_read_the_audit_log(): void
    {
        $this->actingAs(User::factory()->documentController()->create())
            ->get(route('audit.index'))
            ->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('audit.index'))
            ->assertOk();
    }

    #[Test]
    public function only_admins_and_above_can_change_settings(): void
    {
        $this->actingAs(User::factory()->reviewer()->create())
            ->get(route('settings.index'))
            ->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('settings.index'))
            ->assertOk();
    }

    #[Test]
    public function only_a_super_admin_may_delete_a_source(): void
    {
        // Deleting a source cascades to every document indexed from it.
        $source = DocumentSource::factory()->create();

        $this->assertFalse(User::factory()->admin()->create()->can('delete', $source));
        $this->assertTrue(User::factory()->superAdmin()->create()->can('delete', $source));
    }

    #[Test]
    public function the_upload_source_cannot_be_edited_or_deleted(): void
    {
        $uploadSource = DocumentSource::factory()->upload()->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->assertFalse($superAdmin->can('update', $uploadSource));
        $this->assertFalse($superAdmin->can('delete', $uploadSource));
    }

    #[Test]
    public function a_viewer_may_not_download_a_document(): void
    {
        $document = Document::factory()->create();

        $this->assertFalse(User::factory()->viewer()->create()->can('download', $document));
        $this->assertTrue(User::factory()->documentController()->create()->can('download', $document));
    }

    #[Test]
    public function a_deactivated_account_is_signed_out_on_its_next_request(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $user->update(['status' => UserStatus::INACTIVE]);

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /**
     * @return array<string, array{UserRole}>
     */
    public static function everyRole(): array
    {
        $cases = [];

        foreach (UserRole::cases() as $role) {
            $cases[$role->value] = [$role];
        }

        return $cases;
    }
}
