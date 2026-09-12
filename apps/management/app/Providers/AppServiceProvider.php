<?php

namespace App\Providers;

use App\Models\ServicePackage;
use App\Policies\PackagePolicy;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        \Laravel\Passport\Passport::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Laravel\Passport\Passport::tokensCan(\App\Services\Agent\McpConnections::SCOPES);
        \Laravel\Passport\Passport::setDefaultScope(['mcp:use']);
        \Laravel\Passport\Passport::tokensExpireIn(now()->addMinutes(30));
        \Laravel\Passport\Passport::refreshTokensExpireIn(now()->addDays(7));
        \Laravel\Passport\Passport::authorizationView(fn ($parameters) => view('mcp.authorize', $parameters));
        \Illuminate\Support\Facades\Event::listen(\Laravel\Passport\Events\AccessTokenCreated::class,
            [\App\Services\Agent\McpConnections::class, 'issued']);
        \App\Models\User::updated(function (\App\Models\User $user) {
            if ($user->wasChanged(['is_active', 'is_admin', 'role', 'password', 'email', 'session_version'])) {
                \App\Models\McpConnection::where('user_id', $user->id)->whereNull('revoked_at')
                    ->each(fn ($connection) => app(\App\Services\Agent\McpConnections::class)->revoke($connection));
                \Illuminate\Support\Facades\DB::table('oauth_auth_codes')->where('user_id', $user->id)->update(['revoked' => true]);
            }
        });
        // Single resources serialize flat (no "data" wrapper) so Inertia props map
        // cleanly to the React models. Paginated collections still expose data/meta.
        JsonResource::withoutWrapping();

        // ServicePackage does not follow the default {Model}Policy naming, so map it explicitly.
        Gate::policy(ServicePackage::class, PackagePolicy::class);

        Vite::prefetch(concurrency: 3);
    }
}
