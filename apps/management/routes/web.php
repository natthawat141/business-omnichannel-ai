<?php

use App\Http\Controllers\Admin\ApiTokenController;
use App\Http\Controllers\Admin\AiSetupController;
use App\Http\Controllers\Admin\AgentChangeController;
use App\Http\Controllers\Admin\BusinessProfileController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\FaqController;
use App\Http\Controllers\Admin\GuideController;
use App\Http\Controllers\Admin\ImportExportController;
use App\Http\Controllers\Admin\KnowledgeEntryController;
use App\Http\Controllers\Admin\PackageCategoryController;
use App\Http\Controllers\Admin\PackageController;
use App\Http\Controllers\Admin\PropertyImageUploadController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use Illuminate\Support\Facades\Route;
use App\Support\AgentSetupExamples;

// Public instructions only: deliberately no authenticated-user data or token queries.
Route::get('/docs/remote-mcp', function () {
    return response()->view('docs.remote-mcp', ['baseUrl' => rtrim(config('app.url'), '/'),
        'remoteEnabled' => (bool) config('remote-mcp.enabled')])->header('Cache-Control', 'no-store');
})->name('docs.remote-mcp');

Route::get('/docs/agent-setup', function () {
    $baseUrl = rtrim(config('app.url'), '/');

    return response()->view('docs.agent-setup', [
        'baseUrl' => $baseUrl,
        'antigravityConfig' => AgentSetupExamples::antigravity($baseUrl),
    ])->header('Cache-Control', 'no-store');
})->name('docs.agent-setup');

// This is an internal admin tool only — root goes to the dashboard (or login).
Route::get('/', fn () => redirect()->route(auth()->check() ? 'admin.dashboard' : 'login'))->name('home');

// Guest authentication.
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('login.store');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::get('/reset-password', [\App\Http\Controllers\Auth\ResetPasswordController::class, 'create'])->name('password.reset');
Route::post('/reset-password', [\App\Http\Controllers\Auth\ResetPasswordController::class, 'store'])->middleware('throttle:10,1')->name('password.update');

// Protected staff area; sensitive routes remain administrator-only.
Route::middleware(['auth', \App\Http\Middleware\StaffAccess::class])->prefix('admin')->name('admin.')->group(function () {
    Route::resource('users', \App\Http\Controllers\Admin\UserController::class)->except(['show', 'destroy']);
    Route::post('/users/{user}/password-link', [\App\Http\Controllers\Admin\UserController::class, 'passwordLink'])->middleware('throttle:5,1');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/guide', GuideController::class)->name('guide');
    Route::get('/ai-setup', [AiSetupController::class, 'index'])->name('ai-setup.index');
    Route::delete('/ai-setup/connections/{connection}', [AiSetupController::class, 'disconnect'])->name('ai-setup.disconnect');
    Route::post('/ai-setup/keys', [AiSetupController::class, 'store'])->middleware('throttle:5,1')->name('ai-setup.store');
    Route::delete('/ai-setup/keys/{token}', [AiSetupController::class, 'destroy'])->name('ai-setup.destroy');

    // An external agent can only submit proposals; an administrator reviews
    // the visible diff and performs this explicit apply action.
    Route::get('/agent-changes', [AgentChangeController::class, 'index'])->name('agent-changes.index');
    Route::post('/agent-changes/bulk-apply', [AgentChangeController::class, 'bulkApply'])->name('agent-changes.bulk-apply');
    Route::get('/agent-changes/{agentChange}', [AgentChangeController::class, 'show'])->name('agent-changes.show');
    Route::post('/agent-changes/{agentChange}/approve', [AgentChangeController::class, 'approve'])->name('agent-changes.approve');
    Route::post('/agent-changes/{agentChange}/apply', [AgentChangeController::class, 'apply'])->name('agent-changes.apply');
    Route::post('/agent-changes/{agentChange}/reject', [AgentChangeController::class, 'reject'])->name('agent-changes.reject');

    Route::resource('package-categories', PackageCategoryController::class)
        ->parameters(['package-categories' => 'category'])
        ->except('show');
    Route::resource('packages', PackageController::class)->except('show');
    Route::post('/property-images/direct-upload', PropertyImageUploadController::class)
        ->middleware('throttle:10,1')
        ->name('property-images.direct-upload');
    Route::resource('faqs', FaqController::class)->except('show');
    Route::resource('knowledge', KnowledgeEntryController::class)
        ->parameters(['knowledge' => 'knowledge'])
        ->except('show');

    // Singleton settings page: structured business facts the AI composes into its prompt.
    Route::get('/business-profile', [BusinessProfileController::class, 'edit'])->name('business-profile.edit');
    Route::put('/business-profile', [BusinessProfileController::class, 'update'])->name('business-profile.update');

    // Revocable hashed bearer tokens for the knowledge API.
    Route::get('/api-tokens', [ApiTokenController::class, 'index'])->name('api-tokens.index');
    Route::post('/api-tokens', [ApiTokenController::class, 'store'])->name('api-tokens.store');
    Route::delete('/api-tokens/{token}', [ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');

    // Safe package/promotion import only: preview before confirming, never overwrite by code.
    Route::get('/imports', [ImportExportController::class, 'index'])->name('imports.index');
    Route::post('/imports/packages/preview', [ImportExportController::class, 'preview'])->name('imports.preview');
    Route::post('/imports/packages/confirm', [ImportExportController::class, 'confirm'])->name('imports.confirm');
    Route::post('/imports/packages/cancel', [ImportExportController::class, 'cancel'])->name('imports.cancel');
    Route::get('/imports/packages/template', [ImportExportController::class, 'template'])->name('imports.template');
    Route::get('/exports/packages', [ImportExportController::class, 'export'])->name('exports.packages');

    // Retired UI: old bookmarks/clients must not upload, cancel or delete evidence.
    Route::any('/documents/{path?}', function () {
        \Illuminate\Support\Facades\Gate::authorize('viewAny', \App\Models\DocumentSource::class);
        abort(410, 'ระบบอัปโหลดเอกสารถูกยกเลิกแล้ว กรุณาให้ AI อ่าน PDF แล้วส่งข้อเสนอข้อมูลผ่าน MCP');
    })->where('path', '.*');
});
