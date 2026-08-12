<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->registerGates();
        $this->configureModels();
        $this->configurePasswords();
    }

    /**
     * Ability-level gates for actions that are not tied to one model.
     *
     * Model-specific rules stay in policies; these cover the navigation and
     * page-level checks where there is no instance to reason about yet.
     */
    private function registerGates(): void
    {
        Gate::define('manage-sources', fn (User $user) => $user->role->canManageSources());
        Gate::define('upload-document', fn (User $user) => $user->role->canManageDocuments());
        Gate::define('view-audit-log', fn (User $user) => $user->role->canViewAuditLog());
        Gate::define('manage-users', fn (User $user) => $user->role->canManageUsers());
    }

    private function configureModels(): void
    {
        // Fail loudly on a typo'd attribute or an unguarded mass assignment
        // rather than silently writing nothing. Off in production so a stray
        // access cannot take a page down for a Document Controller.
        Model::shouldBeStrict(! $this->app->isProduction());

        // A long-running scan of a large share can hold a transaction open;
        // surfacing slow queries in development keeps that visible.
        if ($this->app->environment('local')) {
            DB::whenQueryingForLongerThan(2000, function (): void {
                logger()->warning('A database query took longer than 2 seconds.');
            });
        }
    }

    private function configurePasswords(): void
    {
        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(12)->letters()->mixedCase()->numbers()->symbols()->uncompromised()
            : Password::min(8));
    }
}
