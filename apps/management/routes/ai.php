<?php

use App\Http\Controllers\Auth\McpOAuthController;
use App\Http\Middleware\McpOAuthBoundary;
use App\Http\Middleware\RemoteMcpAccess;
use App\Mcp\ManagementServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use Laravel\Passport\Http\Controllers\DenyAuthorizationController;

Route::middleware([McpOAuthBoundary::class, 'throttle:60,1'])->group(function () {
    Route::get('/.well-known/oauth-authorization-server', [McpOAuthController::class, 'authorizationMetadata']);
    Route::get('/.well-known/oauth-protected-resource', [McpOAuthController::class, 'resourceMetadata']);
    Route::get('/.well-known/oauth-protected-resource/mcp', [McpOAuthController::class, 'resourceMetadata']);
    Route::post('/oauth/register', [McpOAuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/oauth/token', [AccessTokenController::class, 'issueToken'])->name('passport.token');
});
Route::middleware(['web', McpOAuthBoundary::class, 'throttle:30,1'])->group(function () {
    Route::get('/oauth/authorize', [AuthorizationController::class, 'authorize'])->name('passport.authorizations.authorize');
    Route::post('/oauth/authorize', [ApproveAuthorizationController::class, 'approve'])->middleware('auth')->name('passport.authorizations.approve');
    Route::delete('/oauth/authorize', [DenyAuthorizationController::class, 'deny'])->middleware('auth')->name('passport.authorizations.deny');
});
Mcp::web('/mcp', ManagementServer::class)->middleware(['throttle:60,1', RemoteMcpAccess::class]);
