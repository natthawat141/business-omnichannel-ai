<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Support\AgentSetupExamples;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class AiSetupController extends Controller
{
    private const PREFIX = 'AI Setup: ';

    public function index(Request $request): Response
    {
        abort_unless((bool) $request->user()?->is_admin, 403);

        return Inertia::render($request->boolean('advanced') ? 'AiSetup' : 'AiConnect', [
            'remoteEnabled' => (bool) config('remote-mcp.enabled'),
            'connections' => \App\Models\McpConnection::query()->where('user_id', $request->user()->id)
                ->latest('id')->limit(30)->get()->map(fn ($connection) => [
                    'id' => $connection->id,
                    'name' => \Laravel\Passport\Client::find($connection->client_id)?->name ?? 'AI app',
                    'scopes' => collect($connection->scopes)->map(fn ($scope) => \App\Services\Agent\McpConnections::SCOPES[$scope] ?? $scope),
                    'expires_at' => $connection->expires_at->toIso8601String(),
                    'revoked_at' => $connection->revoked_at?->toIso8601String(),
                    'last_used_at' => $connection->last_used_at?->toIso8601String(),
                ]),
            'baseUrl' => rtrim(config('app.url'), '/'),
            'antigravityConfig' => AgentSetupExamples::antigravity(rtrim(config('app.url'), '/')),
            'tokens' => ApiToken::query()->where('name', 'like', self::PREFIX.'%')
                ->where('is_protected', false)->latest('id')->limit(50)
                ->get(['id', 'name', 'abilities', 'expires_at', 'revoked_at', 'last_used_at']),
        ])->toResponse($request)->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
        $data = $request->validate([
            'client' => ['required', Rule::in(['cli', 'codex', 'claude-code', 'antigravity'])],
            'minutes' => ['required', 'integer', Rule::in([15, 60, 240])],
            'scope' => ['nullable', Rule::in(['document_metadata', 'catalog_proposals'])],
            'proposal_entities' => ['required_if:scope,catalog_proposals', 'array', 'min:1', 'max:3'],
            'proposal_entities.*' => [Rule::in(['catalog', 'faq', 'knowledge']), 'distinct'],
        ]);
        $scope = $data['scope'] ?? 'document_metadata';
        $abilities = $scope === 'catalog_proposals'
            ? array_merge(['agent:read', 'changes:write'], array_map(fn (string $entity) => 'agent:'.$entity, $data['proposal_entities']))
            : ['documents:read'];
        $issued = ApiToken::issue(
            self::PREFIX.$data['client'].($scope === 'catalog_proposals' ? ' proposals' : ''),
            $abilities,
            now()->addMinutes((int) $data['minutes'])
        );
        $issued['token']->forceFill(['user_id' => $request->user()->id])->save();

        return response()->json([
            'key' => $issued['plainText'],
            'id' => $issued['token']->id,
            'expires_at' => $issued['token']->expires_at->toIso8601String(),
        ], 201)->header('Cache-Control', 'no-store');
    }

    public function disconnect(Request $request, \App\Models\McpConnection $connection, \App\Services\Agent\McpConnections $connections): JsonResponse
    {
        abort_unless($request->user()?->is_admin, 403);
        abort_unless($connection->user_id === $request->user()->id, 404);
        $connections->revoke($connection);
        return response()->json(['revoked' => true])->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request, ApiToken $token): JsonResponse
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
        abort_unless(! $token->is_protected && str_starts_with($token->name, self::PREFIX), 404);
        $token->update(['revoked_at' => now()]);

        return response()->json(['revoked' => true])->header('Cache-Control', 'no-store');
    }
}
