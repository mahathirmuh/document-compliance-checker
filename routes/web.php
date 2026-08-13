<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Livewire\Audit\AuditIndex;
use App\Livewire\Dashboard;
use App\Livewire\Documents\DocumentCompare;
use App\Livewire\Documents\DocumentIndex;
use App\Livewire\Documents\DocumentShow;
use App\Livewire\Documents\UploadDocument;
use App\Livewire\Settings\SettingsPanel;
use App\Livewire\Sources\ScanHistory;
use App\Livewire\Sources\SourceForm;
use App\Livewire\Sources\SourceIndex;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| Everything except the login screen sits behind `auth`. Authorisation is a
| second, separate layer: each Livewire component authorises in mount() and
| again in any method that changes state, so a route being reachable never
| implies the action is permitted (CLAUDE.md 12).
|
*/

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');

    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/dashboard', Dashboard::class)->name('dashboard');

    /* --- Documents --- */
    Route::get('/documents', DocumentIndex::class)->name('documents.index');
    Route::get('/documents/upload', UploadDocument::class)->name('documents.upload');
    Route::get('/documents/{document}', DocumentShow::class)->name('documents.show');
    Route::get('/documents/{document}/compare', DocumentCompare::class)->name('documents.compare');

    /* --- Sources --- */
    Route::get('/sources', SourceIndex::class)->name('sources.index');
    Route::get('/sources/create', SourceForm::class)->name('sources.create');
    Route::get('/sources/{source}/edit', SourceForm::class)->name('sources.edit');
    Route::get('/sources/{source}/scans', ScanHistory::class)->name('sources.scans');

    /* --- Administration --- */
    Route::get('/settings', SettingsPanel::class)->name('settings.index');
    Route::get('/audit', AuditIndex::class)->name('audit.index');
});
