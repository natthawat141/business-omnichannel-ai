<?php

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use App\Models\AgentChangeSet;
use App\Models\ApiToken;
use App\Services\Agent\AgentChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * API for an external coding agent to prepare a bounded proposal. This
 * controller deliberately has no apply/publish endpoint: only an authenticated
 * administrator can review and apply a stored change set through the web UI.
 */
class AgentChangeApiController extends Controller
{
    private const MAX_JSON_BYTES = 1048576;

    public function __construct(private readonly AgentChangeService $changes) {}

    public function schema(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->changes->schema($this->token($request))]);
    }

    public function records(Request $request, string $entity): JsonResponse
    {
        return response()->json(['data' => $this->changes->records($this->token($request), $entity, $request->query())]);
    }

    public function record(Request $request, string $entity, int $id): JsonResponse
    {
        return response()->json(['data' => $this->changes->record($this->token($request), $entity, $id)]);
    }

    public function preview(Request $request): JsonResponse
    {
        $this->assertBoundedJson($request);

        return response()->json(['data' => $this->changes->preview($this->token($request), $request->json()->all())]);
    }

    public function submit(Request $request): JsonResponse
    {
        $this->assertBoundedJson($request);
        $key = (string) $request->header('Idempotency-Key', '');
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/D', $key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Idempotency-Key must be 8–128 safe characters.']);
        }

        try {
            $result = $this->changes->submit($this->token($request), $request->json()->all(), $key);
        } catch (ConflictHttpException $exception) {
            return response()->json(['message' => 'Idempotency key conflict.'], 409);
        }

        return response()->json(['data' => $this->changeSet($result['change_set'])], $result['created'] ? 201 : 200);
    }

    public function show(Request $request, string $changeSet): JsonResponse
    {
        if (! Str::isUuid($changeSet)) {
            abort(404);
        }

        return response()->json(['data' => $this->changeSet($this->changes->forToken($this->token($request), $changeSet))]);
    }

    private function token(Request $request): ApiToken
    {
        $token = $request->attributes->get('api_token');
        abort_unless($token instanceof ApiToken, 401);

        return $token;
    }

    private function assertBoundedJson(Request $request): void
    {
        if (strlen($request->getContent()) > self::MAX_JSON_BYTES) {
            throw ValidationException::withMessages(['body' => 'Request body exceeds the 1 MiB proposal limit.']);
        }
    }

    /** @return array<string, mixed> */
    private function changeSet(AgentChangeSet $changeSet): array
    {
        return [
            'id' => $changeSet->id,
            'status' => $changeSet->status,
            'operation_count' => $changeSet->operation_count,
            'schema_versions' => $changeSet->schema_versions,
            'review_note' => $changeSet->review_note,
            'created_at' => $changeSet->created_at?->toIso8601String(),
            'reviewed_at' => $changeSet->reviewed_at?->toIso8601String(),
            'applied_at' => $changeSet->applied_at?->toIso8601String(),
            'operations' => $changeSet->operations->map(fn ($operation) => [
                'sequence' => $operation->sequence,
                'entity' => $operation->entity_type,
                'action' => $operation->action,
                'target_id' => $operation->target_id,
                'client_ref' => $operation->client_ref,
                'parent_client_ref' => $operation->parent_client_ref,
                'expected_version' => $operation->expected_version,
                'payload' => $operation->payload,
                'sources' => $operation->sources,
                'preview' => $operation->preview,
            ])->values()->all(),
        ];
    }
}
