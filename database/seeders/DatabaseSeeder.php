<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Documents\DocumentUploadService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Seeds only what the application cannot run without.
 *
 * No sample documents and no sample sources: this seeder is expected to run
 * against the real internal database, and inventing controlled documents
 * there would be worse than useless.
 *
 * The first administrator's password is taken from SEED_ADMIN_PASSWORD when
 * present, and otherwise generated and printed once. It is never written to
 * a file and never committed.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->seedSuperAdmin();
        $this->seedUploadSource();
    }

    private function seedSuperAdmin(): void
    {
        $email = (string) env('SEED_ADMIN_EMAIL', 'admin@example.com');

        if (User::query()->where('email', $email)->exists()) {
            $this->command?->warn("Super admin [{$email}] already exists; leaving it untouched.");

            return;
        }

        $password = (string) env('SEED_ADMIN_PASSWORD', '');
        $generated = $password === '';

        if ($generated) {
            $password = Str::password(16, symbols: false);
        }

        User::create([
            'name' => (string) env('SEED_ADMIN_NAME', 'System Administrator'),
            'email' => $email,
            'password' => Hash::make($password),
            'role' => UserRole::SUPER_ADMIN,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);

        $this->command?->info("Created super admin [{$email}].");

        if ($generated) {
            $this->command?->newLine();
            $this->command?->warn('Generated password (shown once - store it now, then change it):');
            $this->command?->line("    {$password}");
            $this->command?->newLine();
        }
    }

    /**
     * Ensure the singleton upload source exists.
     *
     * Created here as well as on first upload so the sources screen is not
     * empty on a fresh install.
     */
    private function seedUploadSource(): void
    {
        app(DocumentUploadService::class)->uploadSource();

        $this->command?->info('Manual upload source is ready.');
    }
}
