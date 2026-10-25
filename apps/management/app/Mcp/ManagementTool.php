<?php

namespace App\Mcp;

use App\Http\Controllers\Api\Agent\AgentChangeApiController;
use App\Http\Controllers\Api\DocumentApiController;
use App\Models\ApiToken;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ManagementTool extends Tool
{
    public const DEFINITIONS = [
        'document_list' => ['List safe document names/statuses; start with limit=10,page=1. Use meta.current_page/last_page to continue. No PDF content or download URLs. Empty data means no matching documents.', 'documents:read'],
        'document_get' => ['Read safe metadata for an ID returned by document_list. Does not read PDF content. Use the client file capability only for a file explicitly supplied by the user.', 'documents:read'],
        'agent_schema' => ['Start here before searching or proposing business data. Returns permitted entities, writable fields, profile definitions, versions and limits. Never infer unlisted permissions or generate SQL.', 'agent:read'],
        'agent_records_search' => ['Search permitted catalog/FAQ/knowledge records. Start with page=1,limit=20; follow next_page with identical filters while has_more=true. Empty items mean no exact match; ask before loosening filters. Read agent_record_get for complete details and lock_version.', 'agent:read'],
        'agent_record_get' => ['Read a scoped record by entity and ID returned by agent_records_search. Retain lock_version as expected_version for update/archive/restore. If missing, search again; never guess IDs.', 'agent:read'],
        'agent_changes_preview' => ['Validate operations and return a diff without saving a proposal or changing live data. Read agent_schema and current records first. Correct validation fields before retrying; preview is not submission.', 'changes:write'],
        'agent_changes_submit' => ['Only when the user requests submission: save a previewed immutable proposal for human review, NOT live changes. Keep source references and reuse idempotency_key only for the identical batch. Human approve/apply/publish is separate.', 'changes:write'],
        'agent_changes_get' => ['Read the status of a proposal owned by this connection. Use the UUID returned by agent_changes_submit. submitted means waiting for human review, not applied or published.', 'agent:read'],
    ];

    private const TITLES = [
        'document_list' => 'List document metadata', 'document_get' => 'Read document metadata',
        'agent_schema' => 'Read permitted data schema', 'agent_records_search' => 'Search business records',
        'agent_record_get' => 'Read record and version', 'agent_changes_preview' => 'Preview proposed changes',
        'agent_changes_submit' => 'Submit proposal for human review', 'agent_changes_get' => 'Check proposal status',
    ];

    public function __construct(string $name, string $description)
    {
        $this->name = $name;
        $this->description = $description;
    }

    public function toArray(): array
    {
        $integer = ['type' => 'integer', 'minimum' => 1];
        $entity = ['type' => 'string', 'enum' => ['catalog', 'faq', 'knowledge']];
        $operation = ['type' => 'object', 'additionalProperties' => false, 'required' => ['entity', 'action'], 'properties' => [
            'entity' => $entity,
            'action' => ['type' => 'string', 'enum' => ['create', 'update', 'archive', 'restore'], 'description' => 'create requires client_ref (omit target_id/expected_version); update/archive/restore require target_id and expected_version (from lock_version).'],
            'target_id' => $integer + ['description' => 'Record ID for update, archive, or restore.'],
            'expected_version' => $integer + ['description' => 'Current lock_version of the target record.'],
            'client_ref' => ['type' => 'string', 'maxLength' => 60, 'description' => 'Unique lowercase reference (e.g. faq-1) required for create operations.'],
            'parent_client_ref' => ['type' => 'string', 'maxLength' => 60],
            'payload' => ['type' => 'object', 'description' => 'Fields permitted by agent_schema. faq: question_th, answer_th, question_en, answer_en, category, tags. knowledge: title, body, type, category, tags, source_url. catalog: name_th, description_th, price, transaction_type, availability, location_text, etc. No publish/apply flags.'],
            'sources' => ['type' => 'array', 'maxItems' => 40, 'items' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                'document_id' => $integer, 'external_label' => ['type' => 'string', 'maxLength' => 255],
                'page' => $integer + ['maximum' => 10000], 'field_paths' => ['type' => 'array', 'maxItems' => 40, 'items' => ['type' => 'string', 'maxLength' => 120]],
            ]]],
        ]];
        [$properties, $required] = match ($this->name) {
            'document_list' => [['limit' => $integer + ['maximum' => 50], 'page' => $integer + ['maximum' => 1000],
                'status' => ['type' => 'string', 'enum' => ['uploaded', 'extracting', 'ready', 'ocr_required', 'failed']]], []],
            'document_get' => [['id' => $integer], ['id']],
            'agent_schema' => [[], []],
            'agent_records_search' => [['entity' => $entity + ['description' => 'Choose an entity returned by agent_schema.'], 'query' => ['type' => 'string', 'maxLength' => 100, 'description' => 'Exact search constraint, e.g. a supplied name, code or area. Do not silently remove it on empty results.'],
                'profile' => ['type' => 'string', 'enum' => array_keys(app(\App\Services\Catalog\CatalogProfiles::class)->schemas())],
                'record_kind' => ['type' => 'string', 'enum' => ['group', 'variant', 'offer']],
                'parent_id' => $integer,
                'facility' => ['type' => 'string', 'enum' => \App\Services\Catalog\CatalogProfiles::FACILITIES],
                'limit' => $integer + ['maximum' => 50, 'default' => 20], 'page' => $integer + ['maximum' => 1000, 'default' => 1, 'description' => 'Start at 1; continue with next_page using the same filters.'], 'include_archived' => ['type' => 'boolean', 'default' => false]], ['entity']],
            'agent_record_get' => [['entity' => $entity, 'id' => $integer], ['entity', 'id']],
            'agent_changes_get' => [['id' => ['type' => 'string', 'format' => 'uuid']], ['id']],
            default => [array_merge(['operations' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'items' => $operation]],
                $this->name === 'agent_changes_submit' ? ['idempotency_key' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 128]] : []),
                $this->name === 'agent_changes_submit' ? ['operations', 'idempotency_key'] : ['operations']],
        };
        return ['name' => $this->name, 'title' => self::TITLES[$this->name], 'description' => $this->description,
            'inputSchema' => ['type' => 'object', 'properties' => $properties ?: (object) [], 'required' => $required, 'additionalProperties' => false],
            'outputSchema' => ['type' => 'object', 'required' => ['data', 'next_steps'], 'properties' => [
                'data' => $this->name === 'document_list' ? ['type' => 'array', 'items' => ['type' => 'object']]
                    : ['type' => 'object'],
                'meta' => ['type' => 'object'],
                'next_steps' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 5],
            ], 'additionalProperties' => false],
            'annotations' => ['readOnlyHint' => $this->name !== 'agent_changes_submit', 'destructiveHint' => false, 'openWorldHint' => false,
                'idempotentHint' => true]];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $principal = request()->attributes->get('api_token');
        if (! $principal instanceof ApiToken || ! in_array(self::DEFINITIONS[$this->name][1], $principal->abilities, true)) {
            return $this->failure('permission_denied', 'Permission denied for this tool.', ['Reconnect only after the user approves the required permission: '.self::DEFINITIONS[$this->name][1].'.']);
        }
        try {
            $args = $request->all();
            $schema = $this->toArray()['inputSchema'];
            if (array_diff(array_keys($args), array_keys((array) $schema['properties']))) {
                return $this->failure('invalid_arguments', 'Unknown argument.', ['Read tools/list and use only the inputSchema properties.']);
            }
            $rules = [];
            foreach ((array) $schema['properties'] as $field => $definition) {
                $rules[$field] = [in_array($field, $schema['required'], true) ? 'required' : 'sometimes'];
                if (isset($definition['type'])) {
                    $rules[$field][] = match ($definition['type']) { 'integer' => 'integer', 'boolean' => 'boolean', 'array' => 'array', default => 'string' };
                }
                foreach (['minimum' => 'min', 'maximum' => 'max', 'minLength' => 'min', 'maxLength' => 'max', 'minItems' => 'min', 'maxItems' => 'max'] as $key => $rule) {
                    if (isset($definition[$key])) $rules[$field][] = $rule.':'.$definition[$key];
                }
                if (isset($definition['enum'])) $rules[$field][] = \Illuminate\Validation\Rule::in($definition['enum']);
                if (($definition['format'] ?? '') === 'uuid') $rules[$field][] = 'uuid';
            }
            Validator::make($args, $rules)->validate();
            if (in_array($this->name, ['agent_records_search', 'agent_record_get'], true)
                && isset($args['entity'])
                && ! in_array('agent:'.$args['entity'], $principal->abilities, true)
                && ! in_array('*', $principal->abilities, true)) {
                return $this->failure('permission_denied', 'This connection is not authorized for the requested entity.', [
                    'Read agent_schema to see permitted entities. Ask the user to reconnect with the required entity permission only if they approve it.',
                ]);
            }
            $internal = HttpRequest::create('/internal/mcp', 'GET', $args);
            $internal->attributes->set('api_token', $principal);
            $controller = app(AgentChangeApiController::class);
            if (isset($args['operations'])) {
                $internal = HttpRequest::create('/internal/mcp', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'],
                    json_encode(['operations' => $args['operations']], JSON_THROW_ON_ERROR));
                $internal->attributes->set('api_token', $principal);
                $internal->headers->set('Idempotency-Key', $args['idempotency_key'] ?? '');
            }
            $result = match ($this->name) {
                'document_list' => app(DocumentApiController::class)->index($internal),
                'document_get' => app(DocumentApiController::class)->show($args['id']),
                'agent_schema' => $controller->schema($internal),
                'agent_records_search' => $controller->records($internal, $args['entity']),
                'agent_record_get' => $controller->record($internal, $args['entity'], (int) $args['id']),
                'agent_changes_preview' => $controller->preview($internal),
                'agent_changes_submit' => $controller->submit($internal),
                'agent_changes_get' => $controller->show($internal, $args['id']),
            };
            if ($result->getStatusCode() >= 400) {
                return $this->statusFailure($result->getStatusCode());
            }
            $payload = $result->getData(true);
            $payload['next_steps'] = $this->nextSteps($payload);

            return Response::structured($payload);
        } catch (ValidationException $exception) {
            $details = [];
            foreach (array_slice($exception->errors(), 0, 20, true) as $field => $messages) {
                $details[mb_substr($field, 0, 160)] = mb_substr((string) ($messages[0] ?? 'Invalid value.'), 0, 160);
            }
            return $this->failure('invalid_arguments', 'Validation failed. No success has been confirmed.', ['Correct the listed fields using tools/list and agent_schema, then retry.'], $details);
        } catch (ModelNotFoundException) {
            return $this->statusFailure(404);
        } catch (HttpExceptionInterface $exception) {
            return $this->statusFailure($exception->getStatusCode());
        } catch (Throwable) {
            // Do not leak or log supplied business content, secrets or database diagnostics.
            return $this->failure('temporarily_unavailable', 'Management is temporarily unavailable. No success has been confirmed.', ['Retry later with bounded backoff. For a submit retry, preserve the identical batch and idempotency_key.']);
        }
    }

    private function failure(string $code, string $message, array $steps, array $fields = []): ResponseFactory
    {
        $error = ['error' => ['code' => $code, 'message' => $message, 'fields' => (object) $fields], 'next_steps' => $steps];

        return Response::make(Response::error(json_encode($error, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))
            ->withStructuredContent($error);
    }

    private function statusFailure(int $status): ResponseFactory
    {
        return match ($status) {
            401, 403 => $this->failure('permission_denied', 'The connection cannot access this resource.', ['Check granted entities with agent_schema. Ask the user to reconnect if permission or authorization has expired.']),
            404 => $this->failure('not_found', 'The requested record or owned proposal was not found.', ['For records, use agent_records_search with the same entity and obtain an existing ID. For proposals, use only an ID returned to this connection.']),
            409 => $this->failure('conflict', 'The record version or idempotency key conflicts with the request.', ['Re-read agent_record_get and preview against the latest lock_version. Retry the identical batch with its existing key; use a new key only for a changed batch.']),
            422 => $this->failure('invalid_arguments', 'The request failed validation.', ['Read tools/list and agent_schema. Correct arguments and preview again before submission.']),
            429 => $this->failure('rate_limited', 'Too many requests.', ['Respect Retry-After and stop repeated immediate retries.']),
            default => $this->failure('temporarily_unavailable', 'No success has been confirmed.', ['Retry later with bounded backoff. Preserve the idempotency key for identical submission retries.']),
        };
    }

    private function nextSteps(array $payload): array
    {
        return match ($this->name) {
            'document_list' => ($payload['meta']['current_page'] ?? 1) < ($payload['meta']['last_page'] ?? 1)
                ? ['Call document_list with page='.(($payload['meta']['current_page'] ?? 1) + 1).' and identical filters. Read document_get only for a returned ID.']
                : ['Read document_get for a returned ID. Empty data is a valid result, not a connection failure.'],
            'document_get' => ['This is metadata only. Read PDF content only through the client file capability for a user-supplied file.'],
            'agent_schema' => ['Use agent_records_search for a permitted entity before preparing changes. Follow the returned field and profile definitions.'],
            'agent_records_search' => ($payload['data']['has_more'] ?? false) && isset($payload['data']['next_page'])
                ? ['Call agent_records_search with page='.$payload['data']['next_page'].' and identical filters. Read details with agent_record_get before proposing.']
                : ['Read agent_record_get for a returned ID. Empty items mean no exact match; do not loosen filters without permission.'],
            'agent_record_get' => ['Keep lock_version as expected_version. Use agent_changes_preview before any requested submission.'],
            'agent_changes_preview' => ['Review the diff and validation results. Fix errors before retrying. Submit only when the user asks; preview has saved no proposal.'],
            'agent_changes_submit' => ['Use agent_changes_get with the returned proposal ID. A human must review and apply in Management; submission has not changed live records.'],
            'agent_changes_get' => ['Report the actual proposal status. Approval, applying changes and publishing are separate human actions in Management.'],
        };
    }
}
