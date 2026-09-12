<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Agent\McpConnections;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class McpOAuthBoundary
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('remote-mcp.enabled'), 404);
        abort_if(strlen($request->getContent()) > 16384, 413);
        abort_if(strlen($request->server('QUERY_STRING', '')) > 8192, 414);
        if ($request->filled('resource') && $request->input('resource') !== McpConnections::endpoint()) {
            return response()->json(['error' => 'invalid_target'], 400);
        }
        if ($request->is('oauth/token')) {
            if (! in_array($request->input('grant_type'), ['authorization_code', 'refresh_token'], true)) {
                return response()->json(['error' => 'unsupported_grant_type'], 400);
            }
            DB::beginTransaction();
            try {
                // Single-business serialization also covers not-yet-exchanged codes.
                User::orderBy('id')->lockForUpdate()->first();
                $response = $next($request);
                // Laravel's routing pipeline may render an exception into an error
                // response before it reaches us. Never commit a partial issuance.
                if ($response->getStatusCode() >= 400) {
                    DB::rollBack();
                } else {
                    DB::commit();
                }
                return $this->privateResponse($response);
            } catch (\Throwable $exception) {
                DB::rollBack();
                throw $exception;
            }
        }
        if ($request->is('oauth/authorize')) {
            if ($request->isMethod('GET')) {
                if ($request->input('code_challenge_method') !== 'S256'
                    || ! is_string($request->input('code_challenge'))
                    || ! preg_match('/^[A-Za-z0-9_-]{43}$/D', $request->input('code_challenge'))) {
                    return response()->json(['error' => 'invalid_request', 'error_description' => 'PKCE S256 is required.'], 400);
                }
                // Always show explicit consent; no silent grant through existing login.
                $request->query->set('prompt', 'consent');
            }
            if ($request->user()) {
                $user = $request->user();
                abort_unless($user->is_active && $user->is_admin
                    && (int) $request->session()->get('staff_version', 0) === $user->session_version, 403);
            }
            return DB::transaction(function () use ($request, $next) {
                User::orderBy('id')->lockForUpdate()->first();
                if ($request->user()) {
                    $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                    abort_unless($user->is_active && $user->is_admin
                        && $user->session_version === $request->user()->session_version, 403);
                }
                return $this->privateResponse($next($request));
            });
        }
        return $this->privateResponse($next($request));
    }

    private function privateResponse($response)
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
        return $response;
    }
}
