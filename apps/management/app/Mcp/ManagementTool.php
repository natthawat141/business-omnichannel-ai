<?php

namespace App\Mcp;

use App\Http\Controllers\Api\Agent\AgentChangeApiController;
use App\Http\Controllers\Api\DocumentApiController;
use App\Models\ApiToken;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ManagementTool extends Tool
{
    public const DEFINITIONS = [
        'document_list' => ['List document metadata with bounded pagination. No PDF content or private download URLs.', 'documents:read'],
        'document_get' => ['Read safe metadata for one document ID.', 'documents:read'],
        'agent_schema' => ['Read the scoped proposal schema and allowed entities before preparing changes. No SQL.', 'agent:read'],
        'agent_records_search' => ['Search scoped records; limit at most 50. Never silently loosen filters.', 'agent:read'],
        'agent_record_get' => ['Read one scoped record and its lock_version before proposing changes.', 'agent:read'],
        'agent_changes_preview' => ['Validate and preview operations without saving a proposal or changing live data.', 'changes:write'],
        'agent_changes_submit' => ['Save an immutable proposal for human review, NOT live changes. Reuse idempotency_key only for the identical batch.', 'changes:write'],
        'agent_changes_get' => ['Read the status of a proposal owned by this connection.', 'agent:read'],
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
            'entity' => $entity, 'action' => ['type' => 'string', 'enum' => ['create', 'update', 'archive', 'restore']],
            'target_id' => $integer, 'expected_version' => $integer,
            'client_ref' => ['type' => 'string', 'maxLength' => 60], 'parent_client_ref' => ['type' => 'string', 'maxLength' => 60],
            'payload' => ['type' => 'object', 'description' => 'Only fields permitted by agent_schema; validated server-side. No publish/apply flags.'],
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
            'agent_records_search' => [['entity' => $entity, 'query' => ['type' => 'string', 'maxLength' => 100],
                'profile' => ['type' => 'string', 'enum' => array_keys(app(\App\Services\Catalog\CatalogProfiles::class)->schemas())],
                'record_kind' => ['type' => 'string', 'enum' => ['group', 'variant', 'offer']],
                'parent_id' => $integer,
                'facility' => ['type' => 'string', 'enum' => \App\Services\Catalog\CatalogProfiles::FACILITIES],
                'limit' => $integer + ['maximum' => 50], 'include_archived' => ['type' => 'boolean']], ['entity']],
            'agent_record_get' => [['entity' => $entity, 'id' => $integer], ['entity', 'id']],
            'agent_changes_get' => [['id' => ['type' => 'string', 'format' => 'uuid']], ['id']],
            default => [array_merge(['operations' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'items' => $operation]],
                $this->name === 'agent_changes_submit' ? ['idempotency_key' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 128]] : []),
                $this->name === 'agent_changes_submit' ? ['operations', 'idempotency_key'] : ['operations']],
        };
        return ['name' => $this->name, 'description' => $this->description,
            'inputSchema' => ['type' => 'object', 'properties' => $properties ?: (object) [], 'required' => $required, 'additionalProperties' => false],
            'annotations' => ['readOnlyHint' => $this->name !== 'agent_changes_submit', 'destructiveHint' => false, 'openWorldHint' => false,
                'idempotentHint' => true]];
    }

    public function handle(Request $request): Response
    {
        $principal = request()->attributes->get('api_token');
        if (! $principal instanceof ApiToken || ! in_array(self::DEFINITIONS[$this->name][1], $principal->abilities, true)) {
            return Response::error('Permission denied. Reconnect with the required permission.');
        }
        try {
            $args = $request->all();
            $schema = $this->toArray()['inputSchema'];
            if (array_diff(array_keys($args), array_keys((array) $schema['properties']))) {
                return Response::error('Unknown argument. Read the tool schema.');
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
            return $result->getStatusCode() >= 400
                ? Response::error('Request rejected ('.$result->getStatusCode().'). Check arguments, scope and idempotency key.')
                : Response::text($result->getContent());
        } catch (ValidationException $exception) {
            return Response::error('Validation failed: '.implode(', ', array_slice(array_keys($exception->errors()), 0, 20)));
        } catch (HttpExceptionInterface $exception) {
            return Response::error('Request rejected ('.$exception->getStatusCode().'). Check permission and record ID.');
        } catch (Throwable) {
            // Do not leak or log supplied business content, secrets or database diagnostics.
            return Response::error('Management is temporarily unavailable. No success has been confirmed.');
        }
    }
}
