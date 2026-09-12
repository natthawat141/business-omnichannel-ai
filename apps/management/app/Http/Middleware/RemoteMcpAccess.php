<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Models\McpConnection;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Token;

class RemoteMcpAccess
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('remote-mcp.enabled'), 404);
        abort_if(strlen($request->getContent()) > 1048576, 413);
        if ($request->hasHeader('Origin')) {
            abort_unless($request->header('Origin') === rtrim(config('app.url'), '/')
                || in_array($request->header('Origin'), config('remote-mcp.redirect_origins'), true), 403);
        }
        return DB::transaction(function () use ($request, $next) {
            User::orderBy('id')->lockForUpdate()->first();
            return $this->authenticated($request, $next);
        });
    }

    private function authenticated(Request $request, Closure $next)
    {
        // Never accept Passport's cookie/session fallback on the remote transport.
        if (! $request->bearerToken()) {
            return $this->unauthorized();
        }
        $user = auth('api')->user();
        $access = $user?->token();
        $token = $access instanceof \Laravel\Passport\AccessToken ? Token::find($access->oauth_access_token_id) : null;
        if (! $user?->is_active || ! $user->is_admin || ! $token instanceof Token || $token->revoked || $token->expires_at->isPast()) {
            return $this->unauthorized();
        }
        $connection = McpConnection::find($token->mcp_connection_id);
        $principal = $connection ? ApiToken::find($connection->api_token_id) : null;
        if (! $connection || $connection->user_id !== $user->id || $connection->revoked_at
            || (string) $token->client_id !== $connection->client_id
            || $connection->expires_at->isPast() || $connection->session_version !== $user->session_version
            || ! $principal || $principal->revoked_at || $principal->expires_at->isPast()) {
            return $this->unauthorized();
        }
        // Refresh scope narrowing must never inherit a broader principal.
        $scopes = $token->scopes;
        sort($scopes);
        if (hash('sha256', json_encode($scopes)) !== $connection->scope_hash) {
            return $this->unauthorized();
        }
        $request->attributes->set('api_token', $principal);
        $connection->update(['last_used_at' => now()]);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');
        return $response;
    }

    private function unauthorized()
    {
        return response()->json(['error' => 'invalid_token'], 401)
            ->header('WWW-Authenticate', 'Bearer resource_metadata="'.rtrim(config('app.url'), '/').'/.well-known/oauth-protected-resource/mcp"')
            ->header('Cache-Control', 'no-store');
    }
}
