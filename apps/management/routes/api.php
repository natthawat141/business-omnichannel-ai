<?php

use App\Http\Controllers\Api\BotInteractionController;
use App\Http\Controllers\Api\BusinessProfileApiController;
use App\Http\Controllers\Api\CatalogSearchController;
use App\Http\Controllers\Api\DocumentApiController;
use App\Http\Controllers\Api\FlexMessageApiController;
use App\Http\Controllers\Api\Agent\AgentChangeApiController;
use App\Http\Controllers\Api\KnowledgeApiController;
use Illuminate\Support\Facades\Route;

/*
 * Read-only knowledge API consumed by the Chatwoot AI service.
 * Guarded by a revocable hashed bearer token and rate limited.
 * All endpoints return only active records.
 */
Route::middleware(['api.token:read', 'throttle:120,1'])->prefix('v1')->name('api.v1.')->group(function () {
    Route::get('/meta', [KnowledgeApiController::class, 'meta'])->name('meta');
    Route::get('/packages', [KnowledgeApiController::class, 'packages'])->name('packages');
    Route::get('/faqs', [KnowledgeApiController::class, 'faqs'])->name('faqs');
    Route::get('/knowledge', [KnowledgeApiController::class, 'knowledge'])->name('knowledge');
    Route::get('/business-profile', [BusinessProfileApiController::class, 'show'])->name('business-profile');
    Route::post('/catalog/search', [CatalogSearchController::class, 'search'])->name('catalog.search');
    Route::get('/catalog/{package}', [CatalogSearchController::class, 'show'])->name('catalog.show');
    Route::get('/flex/carousel', [FlexMessageApiController::class, 'carousel'])->name('flex.carousel');
    Route::post('/flex/carousel', [FlexMessageApiController::class, 'exactCarousel'])->name('flex.carousel.exact');
    Route::get('/flex/catalog/{package}', [FlexMessageApiController::class, 'show'])->name('flex.show');
    Route::get('/flex/loan', [FlexMessageApiController::class, 'loan'])->name('flex.loan');
    Route::get('/flex/consignment', [FlexMessageApiController::class, 'consignment'])->name('flex.consignment');
    Route::get('/flex/about', [FlexMessageApiController::class, 'about'])->name('flex.about');
});

Route::post('/v1/interactions', [BotInteractionController::class, 'store'])
    ->middleware(['api.token:analytics:write', 'throttle:120,1'])
    ->name('api.v1.interactions.store');

/*
 * Read-only Document Intake API consumed by external agent clients (CLI, MCP).
 * Guarded by a revocable hashed bearer token with ability 'documents:read' and rate limited.
 */
Route::middleware(['api.token:documents:read', 'throttle:60,1'])->prefix('v1')->name('api.v1.')->group(function () {
    Route::get('/documents', [DocumentApiController::class, 'index'])->name('documents.index');
    Route::get('/documents/{document}', [DocumentApiController::class, 'show'])->name('documents.show')->whereNumber('document');
});

/*
 * Proposal-only data API for a deliberately scoped external coding agent.
 * It cannot publish, approve or apply; those actions require an admin session
 * and CSRF-protected routes below /admin.
 */
Route::middleware(['api.token:agent:read', 'throttle:60,1'])->prefix('v1/agent')->name('api.v1.agent.')->group(function () {
    Route::get('/schema', [AgentChangeApiController::class, 'schema'])->name('schema');
    Route::get('/records/{entity}', [AgentChangeApiController::class, 'records'])->name('records');
    Route::get('/records/{entity}/{id}', [AgentChangeApiController::class, 'record'])->whereNumber('id')->name('record');
    Route::get('/changes/{changeSet}', [AgentChangeApiController::class, 'show'])->name('changes.show');
});

Route::middleware(['api.token:changes:write', 'throttle:30,1'])->prefix('v1/agent')->name('api.v1.agent.')->group(function () {
    Route::post('/changes/preview', [AgentChangeApiController::class, 'preview'])->name('changes.preview');
    Route::post('/changes', [AgentChangeApiController::class, 'submit'])->name('changes.store');
});
