<?php

namespace App\Http\Controllers\Auth;

use App\Services\Agent\McpConnections;
use Illuminate\Http\Request;
use Laravel\Passport\ClientRepository;

class McpOAuthController
{
    public function register(Request $request, ClientRepository $clients)
    {
        $data = $request->validate([
            'client_name' => ['required', 'string', 'min:1', 'max:100'],
            'redirect_uris' => ['required', 'array', 'min:1', 'max:5'],
            'redirect_uris.*' => ['required', 'string', 'max:2048', function ($attribute, $value, $fail) {
                $parts = parse_url($value);
                $scheme = $parts['scheme'] ?? '';
                $host = $parts['host'] ?? '';
                $local = in_array($host, ['localhost', '127.0.0.1'], true);
                $origin = $scheme.'://'.$host.(! $local && isset($parts['port']) ? ':'.$parts['port'] : '');
                if (! $parts || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
                    || ! in_array($scheme, $local ? ['http', 'https'] : ['https'], true)
                    || ! in_array($origin, config('remote-mcp.redirect_origins'), true)) {
                    $fail('Callback origin is not allowed. Ask the administrator to review this client.');
                }
            }],
            'token_endpoint_auth_method' => ['sometimes', 'in:none'],
            'grant_types' => ['sometimes', 'array', 'max:2'],
            'grant_types.*' => ['in:authorization_code,refresh_token'],
            'response_types' => ['sometimes', 'array', 'size:1'],
            'response_types.*' => ['in:code'],
        ]);
        $client = $clients->createAuthorizationCodeGrantClient(name: $data['client_name'], redirectUris: $data['redirect_uris'], confidential: false, enableDeviceFlow: false);
        return response()->json([
            'client_id' => (string) $client->id, 'client_name' => $client->name,
            'redirect_uris' => $client->redirect_uris,
            'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none', 'scope' => implode(' ', array_keys(McpConnections::SCOPES)),
        ], 201);
    }

    public function authorizationMetadata()
    {
        $base = rtrim(config('app.url'), '/');
        return response()->json([
            'issuer' => $base, 'authorization_endpoint' => $base.'/oauth/authorize',
            'token_endpoint' => $base.'/oauth/token', 'registration_endpoint' => $base.'/oauth/register',
            'response_types_supported' => ['code'], 'code_challenge_methods_supported' => ['S256'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => array_keys(McpConnections::SCOPES),
        ]);
    }

    public function resourceMetadata()
    {
        return response()->json([
            'resource' => McpConnections::endpoint(),
            'authorization_servers' => [rtrim(config('app.url'), '/')],
            'scopes_supported' => array_keys(McpConnections::SCOPES), 'bearer_methods_supported' => ['header'],
        ]);
    }
}
