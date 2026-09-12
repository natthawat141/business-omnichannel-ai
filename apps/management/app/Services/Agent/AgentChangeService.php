<?php

namespace App\Services\Agent;

use App\Models\AgentChangeOperation;
use App\Models\AgentChangeSet;
use App\Models\ApiToken;
use App\Models\DocumentSource;
use App\Models\Faq;
use App\Models\KnowledgeEntry;
use App\Models\PackageCategory;
use App\Models\RecordRevision;
use App\Models\RecordSource;
use App\Models\ServicePackage;
use App\Models\User;
use App\Services\Catalog\AttributeValidator;
use App\Services\Catalog\CatalogProfiles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Bounded proposal engine. It has no generic table/SQL mode and never applies
 * an agent proposal without a separately authenticated administrator action.
 */
class AgentChangeService
{
    private const ENTITIES = ['catalog', 'faq', 'knowledge'];
    private const ACTIONS = ['create', 'update', 'archive', 'restore'];
    private const MAX_OPERATIONS = 50;
    private const MAX_BULK_CHANGE_SETS = 25;

    /** @return array<string, mixed> */
    public function schema(ApiToken $token): array
    {
        $entities = [];
        foreach (self::ENTITIES as $entity) {
            if ($this->allowsEntity($token, $entity)) {
                $entities[] = match ($entity) {
                    'catalog' => ['name' => 'catalog', 'actions' => self::ACTIONS, 'create_defaults' => ['is_published' => false, 'availability' => 'unknown'], 'record_kinds' => ['group', 'variant', 'offer'], 'typed_attributes' => true],
                    'faq' => ['name' => 'faq', 'actions' => self::ACTIONS, 'create_defaults' => ['is_active' => false]],
                    'knowledge' => ['name' => 'knowledge', 'actions' => self::ACTIONS, 'create_defaults' => ['is_active' => false]],
                };
            }
        }
        if ($entities === []) {
            abort(403, 'This token has no permitted agent entity.');
        }

        return [
            'version' => '1.0',
            'catalog_link_fields' => $this->allowsEntity($token, 'catalog') ? [
                'map_url' => ['type' => 'string', 'nullable' => true, 'max_length' => 2048,
                    'scheme' => 'https', 'update' => 'Omit to preserve; null to clear. No server-side fetch or geocoding.'],
            ] : [],
            'catalog_profiles' => $this->allowsEntity($token, 'catalog') ? app(CatalogProfiles::class)->schemas() : [],
            'catalog_profile_rules' => $this->allowsEntity($token, 'catalog') ? [
                'write_fields' => ['profile', 'profile_data'],
                'profile_data_update' => 'Replace the whole object when supplied; omit to preserve.',
                'facts' => 'Project facts: group. Layout ranges: variant. Prices and availability: actual offer only. Description is narrative, never evidence of vacancy or current price.',
                'sources' => 'Use operation sources with page and field_paths such as profile_data.facilities.',
                'search_filters' => ['profile', 'record_kind', 'parent_id', 'facility'],
            ] : [],
            'entities' => $entities,
            'limits' => ['operations_per_change_set' => self::MAX_OPERATIONS, 'json_bytes' => 1048576],
            'rules' => ['no_sql' => true, 'agent_cannot_apply_or_publish' => true, 'sources_are_reference_only' => true],
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function preview(ApiToken $token, array $input): array
    {
        $operations = $this->validatedOperations($token, $input);

        return ['operations' => array_map(fn (array $operation) => $this->previewOperation($operation), $operations), 'warnings' => [
            'A preview does not lock records. Approval revalidates schema, versions and sources.',
            'New records apply as unpublished/inactive drafts; no agent field can publish them.',
        ]];
    }

    /** @param array<string, mixed> $input */
    public function submit(ApiToken $token, array $input, string $idempotencyKey): array
    {
        $operations = $this->validatedOperations($token, $input);
        $canonical = $this->canonical(['operations' => $operations]);
        $hash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($token, $operations, $idempotencyKey, $hash) {
            $existing = AgentChangeSet::query()->where('api_token_id', $token->id)->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing !== null) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw new ConflictHttpException('Idempotency key was already used with different content.');
                }
                return ['change_set' => $existing->load('operations'), 'created' => false];
            }
            $changeSet = AgentChangeSet::create([
                'api_token_id' => $token->id,
                'submitted_by_user_id' => $token->user_id,
                'status' => AgentChangeSet::PROPOSED,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $hash,
                'schema_versions' => $this->schemaVersions($operations),
                'operation_count' => count($operations),
            ]);
            foreach ($operations as $index => $operation) {
                AgentChangeOperation::create([
                    'change_set_id' => $changeSet->id,
                    'sequence' => $index + 1,
                    'entity_type' => $operation['entity'],
                    'action' => $operation['action'],
                    'target_id' => $operation['target_id'] ?? null,
                    'client_ref' => $operation['client_ref'] ?? null,
                    'parent_client_ref' => $operation['parent_client_ref'] ?? null,
                    'expected_version' => $operation['expected_version'] ?? null,
                    'payload' => $operation['payload'] ?? [],
                    'sources' => $operation['sources'] ?? null,
                    'preview' => $this->previewOperation($operation),
                ]);
            }

            return ['change_set' => $changeSet->load('operations'), 'created' => true];
        });
    }

    public function forToken(ApiToken $token, string $id): AgentChangeSet
    {
        return AgentChangeSet::query()->whereKey($id)->where('api_token_id', $token->id)->with('operations')->firstOrFail();
    }

    /**
     * Read only the bounded record views an agent needs to make a proposal.
     * This is intentionally not a generic model/table explorer.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function records(ApiToken $token, string $entity, array $query): array
    {
        $this->assertEntityAllowed($token, $entity);
        $validator = Validator::make($query, [
            'query' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'include_archived' => ['nullable', 'boolean'],
            'profile' => ['sometimes', Rule::in(array_keys(app(CatalogProfiles::class)->schemas()))],
            'record_kind' => ['sometimes', Rule::in(['group', 'variant', 'offer'])],
            'parent_id' => ['sometimes', 'integer', 'min:1'],
            'facility' => ['sometimes', Rule::in(CatalogProfiles::FACILITIES)],
        ]);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
        $data = $validator->validated();
        if ($entity !== 'catalog' && array_intersect(array_keys($data), ['profile', 'record_kind', 'parent_id', 'facility'])) {
            throw ValidationException::withMessages(['filters' => 'Profile filters apply only to catalog.']);
        }
        if (isset($data['facility']) && ($data['profile'] ?? null) !== 'property_project') {
            throw ValidationException::withMessages(['facility' => 'Facility search requires profile=property_project.']);
        }
        $search = trim((string) ($data['query'] ?? ''));
        $limit = (int) ($data['limit'] ?? 20);
        $includeArchived = (bool) ($data['include_archived'] ?? false);
        $builder = match ($entity) {
            'catalog' => ServicePackage::query(),
            'faq' => Faq::query(),
            'knowledge' => KnowledgeEntry::query(),
        };
        if (! $includeArchived) {
            $builder->whereNull('archived_at');
        }
        foreach (['profile', 'record_kind', 'parent_id'] as $field) {
            if (isset($data[$field])) $builder->where($field, $data[$field]);
        }
        if (isset($data['facility'])) $builder->whereJsonContains('profile_data->facilities', $data['facility']);
        if ($search !== '') {
            $builder->where(function ($records) use ($entity, $search) {
                match ($entity) {
                    'catalog' => $records->where('name_th', 'like', "%{$search}%")->orWhere('name_en', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"),
                    'faq' => $records->where('question_th', 'like', "%{$search}%")->orWhere('question_en', 'like', "%{$search}%"),
                    'knowledge' => $records->where('title', 'like', "%{$search}%")->orWhere('category', 'like', "%{$search}%"),
                };
            });
        }

        return [
            'entity' => $entity,
            'items' => $builder->orderByDesc('id')->limit($limit)->get()->map(fn (Model $model) => $this->agentRecord($entity, $model))->all(),
            'limit' => $limit,
        ];
    }

    /** @return array<string, mixed> */
    public function record(ApiToken $token, string $entity, int $id): array
    {
        $this->assertEntityAllowed($token, $entity);
        $model = match ($entity) {
            'catalog' => ServicePackage::query()->findOrFail($id),
            'faq' => Faq::query()->findOrFail($id),
            'knowledge' => KnowledgeEntry::query()->findOrFail($id),
        };

        return $this->agentRecord($entity, $model);
    }

    public function approve(AgentChangeSet $changeSet, User $admin): AgentChangeSet
    {
        return DB::transaction(function () use ($changeSet, $admin) {
            $current = AgentChangeSet::query()->lockForUpdate()->findOrFail($changeSet->id);
            if ($current->status !== AgentChangeSet::PROPOSED) {
                throw ValidationException::withMessages(['change_set' => 'Only a proposed change set can be approved.']);
            }
            if ($current->token?->isRevoked()) {
                $current->update(['status' => AgentChangeSet::SUSPENDED, 'suspended_at' => now()]);
                throw ValidationException::withMessages(['change_set' => 'The originating key was revoked; review trust before proceeding.']);
            }
            $current->update(['status' => AgentChangeSet::APPROVED, 'reviewed_by_user_id' => $admin->id, 'reviewed_at' => now()]);
            return $current;
        });
    }

    public function reject(AgentChangeSet $changeSet, User $admin, ?string $note = null): AgentChangeSet
    {
        if (! in_array($changeSet->status, [AgentChangeSet::PROPOSED, AgentChangeSet::APPROVED], true)) {
            throw ValidationException::withMessages(['change_set' => 'Only pending proposals can be rejected.']);
        }
        $changeSet->update(['status' => AgentChangeSet::REJECTED, 'reviewed_by_user_id' => $admin->id, 'reviewed_at' => now(), 'review_note' => $note]);
        return $changeSet;
    }

    public function apply(AgentChangeSet $changeSet, User $admin): AgentChangeSet
    {
        $applyingId = null;
        try {
            return DB::transaction(function () use ($changeSet, $admin, &$applyingId) {
                $current = AgentChangeSet::query()->with(['operations', 'token'])->lockForUpdate()->findOrFail($changeSet->id);
                $applyingId = $current->id;

                return $this->applyApprovedChangeSet($current, $admin);
            });
        } catch (ValidationException|ConflictHttpException $exception) {
            if ($applyingId !== null) {
                $this->markApplyFailure($applyingId);
            }
            throw $exception;
        }
    }

    /**
     * Apply multiple already-approved review batches as one transaction. The human
     * still selects the batches and confirms the action; an agent can never call it.
     *
     * @param array<int, string> $changeSetIds
     * @return array<int, AgentChangeSet>
     */
    public function applyMany(array $changeSetIds, User $admin): array
    {
        $ids = array_values(array_unique($changeSetIds));
        if ($ids === [] || count($ids) !== count($changeSetIds) || count($ids) > self::MAX_BULK_CHANGE_SETS) {
            throw ValidationException::withMessages(['change_sets' => 'Select between 1 and '.self::MAX_BULK_CHANGE_SETS.' distinct approved change sets.']);
        }

        $applyingId = null;
        try {
            return DB::transaction(function () use ($ids, $admin, &$applyingId) {
                $sets = AgentChangeSet::query()
                    ->with(['operations', 'token'])
                    ->whereIn('id', $ids)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($sets->count() !== count($ids)) {
                    throw ValidationException::withMessages(['change_sets' => 'One or more selected change sets no longer exist.']);
                }
                if ($sets->contains(fn (AgentChangeSet $set) => $set->status !== AgentChangeSet::APPROVED)) {
                    throw ValidationException::withMessages(['change_sets' => 'Only approved change sets can be applied together.']);
                }

                $applied = [];
                foreach ($sets as $current) {
                    $applyingId = $current->id;
                    $applied[] = $this->applyApprovedChangeSet($current, $admin);
                }

                return $applied;
            });
        } catch (ValidationException|ConflictHttpException $exception) {
            if ($applyingId !== null) {
                $this->markApplyFailure($applyingId);
            }
            throw $exception;
        }
    }

    private function applyApprovedChangeSet(AgentChangeSet $current, User $admin): AgentChangeSet
    {
        if ($current->status !== AgentChangeSet::APPROVED) {
            throw ValidationException::withMessages(['change_set' => 'Only an approved change set can be applied.']);
        }
        if ($current->token?->isRevoked()) {
            throw ValidationException::withMessages(['change_set' => 'The originating key was revoked; this proposal is suspended.']);
        }
        $operations = $current->operations->map(fn (AgentChangeOperation $op) => [
            'entity' => $op->entity_type, 'action' => $op->action, 'target_id' => $op->target_id,
            'client_ref' => $op->client_ref, 'parent_client_ref' => $op->parent_client_ref,
            'expected_version' => $op->expected_version, 'payload' => $op->payload ?? [], 'sources' => $op->sources ?? [],
        ])->all();
        // Revalidate against the current schema and live records, under the apply transaction.
        $this->validatedOperations($current->token, ['operations' => $operations]);
        if ($this->canonical($current->schema_versions ?? []) !== $this->canonical($this->schemaVersions($operations))) {
            throw ValidationException::withMessages(['change_set' => 'A referenced catalog schema changed since this proposal was submitted.']);
        }
        $resolved = [];
        foreach ($operations as $operation) {
            $model = $this->applyOperation($current, $admin, $operation, $resolved);
            if ($operation['action'] === 'create') {
                $resolved[$operation['client_ref']] = $model;
            }
        }
        $current->update(['status' => AgentChangeSet::APPLIED, 'applied_by_user_id' => $admin->id, 'applied_at' => now()]);

        return $current->fresh(['operations']);
    }

    private function markApplyFailure(string $changeSetId): void
    {
        $changeSet = AgentChangeSet::query()->find($changeSetId);
        if ($changeSet === null || $changeSet->status !== AgentChangeSet::APPROVED) {
            return;
        }

        $status = ApiToken::query()->whereKey($changeSet->api_token_id)->whereNotNull('revoked_at')->exists()
            ? AgentChangeSet::SUSPENDED
            : AgentChangeSet::CONFLICTED;
        $changeSet->update([
            'status' => $status,
            'suspended_at' => $status === AgentChangeSet::SUSPENDED ? now() : null,
            'review_note' => $status === AgentChangeSet::SUSPENDED ? 'Originating key revoked during apply' : 'Apply validation conflict',
        ]);
    }

    /** @param array<string, mixed> $operation @param array<string, Model> $resolved */
    private function applyOperation(AgentChangeSet $changeSet, User $admin, array $operation, array $resolved): Model
    {
        $entity = $operation['entity'];
        $action = $operation['action'];
        $payload = $operation['payload'] ?? [];
        $model = null;
        $before = null;
        if ($action === 'create') {
            $model = $this->newModel($entity, $payload, $operation, $resolved);
            $model->save();
        } else {
            $model = $this->lockedModel($entity, (int) $operation['target_id']);
            $before = $this->snapshot($model);
            if ($model->lock_version !== (int) $operation['expected_version']) {
                throw ValidationException::withMessages(['operations' => 'A target record changed since this proposal was submitted.']);
            }
            if ($action === 'update') {
                if ($model->archived_at !== null) {
                    throw ValidationException::withMessages(['operations' => 'Restore an archived record before updating it.']);
                }
                if ($entity === 'catalog' && isset($operation['parent_client_ref'])) {
                    $payload['parent_id'] = $this->resolvedParent($operation['parent_client_ref'], $resolved)->id;
                }
                $model->fill($payload);
                $model->lock_version++;
                if ($entity === 'knowledge') {
                    $model->version++;
                    $model->reviewed_at = null;
                }
                $model->save();
            } elseif ($action === 'archive') {
                $model->archive();
                $model->refresh();
            } else {
                $model->restoreFromArchive();
                $model->refresh();
            }
        }
        $after = $this->snapshot($model);
        RecordRevision::create([
            'entity_type' => $entity, 'entity_id' => $model->id, 'change_set_id' => $changeSet->id,
            'api_token_id' => $changeSet->api_token_id, 'user_id' => $admin->id, 'action' => $action,
            'lock_version' => $model->lock_version, 'before' => $before, 'after' => $after, 'created_at' => now(),
        ]);
        $this->saveSources($changeSet, $entity, $model, $operation['sources'] ?? []);
        return $model;
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $operation @param array<string, Model> $resolved */
    private function newModel(string $entity, array $payload, array $operation, array $resolved): Model
    {
        return match ($entity) {
            'catalog' => new ServicePackage($payload + [
                'parent_id' => isset($operation['parent_client_ref']) ? $this->resolvedParent($operation['parent_client_ref'], $resolved)->id : null,
                'record_kind' => $payload['record_kind'] ?? 'offer', 'availability' => $payload['availability'] ?? 'unknown',
                'is_active' => true, 'is_published' => false, 'lock_version' => 1,
            ]),
            'faq' => new Faq($payload + ['is_active' => false, 'lock_version' => 1]),
            'knowledge' => new KnowledgeEntry($payload + ['is_active' => false, 'version' => 1, 'reviewed_at' => null, 'lock_version' => 1]),
        };
    }

    private function lockedModel(string $entity, int $id): Model
    {
        return match ($entity) {
            'catalog' => ServicePackage::query()->lockForUpdate()->findOrFail($id),
            'faq' => Faq::query()->lockForUpdate()->findOrFail($id),
            'knowledge' => KnowledgeEntry::query()->lockForUpdate()->findOrFail($id),
        };
    }

    /** @param array<string, Model> $resolved */
    private function resolvedParent(string $ref, array $resolved): ServicePackage
    {
        $parent = $resolved[$ref] ?? null;
        if (! $parent instanceof ServicePackage) {
            throw ValidationException::withMessages(['parent_client_ref' => 'Parent reference must name an earlier catalog create operation.']);
        }
        return $parent;
    }

    /** @param array<int, array<string, mixed>> $sources */
    private function saveSources(AgentChangeSet $changeSet, string $entity, Model $model, array $sources): void
    {
        foreach ($sources as $source) {
            $document = isset($source['document_id']) ? DocumentSource::query()->findOrFail($source['document_id']) : null;
            if ($document?->isCancelled()) {
                throw ValidationException::withMessages(['sources' => 'Cancelled documents cannot be used as verified evidence.']);
            }
            if ($document !== null && ($changeSet->submitted_by_user_id === null
                || $document->user_id === null
                || (int) $document->user_id !== (int) $changeSet->submitted_by_user_id)) {
                throw ValidationException::withMessages(['sources' => 'Document source does not belong to the key issuer.']);
            }
            RecordSource::create([
                'entity_type' => $entity, 'entity_id' => $model->id, 'change_set_id' => $changeSet->id,
                'document_source_id' => $document?->id, 'external_label' => $source['external_label'] ?? null,
                'page' => $source['page'] ?? null, 'field_paths' => $source['field_paths'] ?? null,
                'verified' => $document !== null, 'created_at' => now(),
            ]);
        }
    }

    /** @param array<string, mixed> $input @return array<int, array<string, mixed>> */
    private function validatedOperations(ApiToken $token, array $input): array
    {
        $validator = Validator::make($input, ['operations' => ['required', 'array', 'min:1', 'max:'.self::MAX_OPERATIONS]]);
        if ($validator->fails()) throw new ValidationException($validator);
        $operations = $input['operations'];
        $errors = [];
        $refs = [];
        foreach ($operations as $index => &$operation) {
            $path = 'operations.'.$index;
            if (! is_array($operation) || array_diff(array_keys($operation), ['entity', 'action', 'target_id', 'client_ref', 'parent_client_ref', 'expected_version', 'payload', 'sources'])) {
                $errors[$path] = 'Operation contains unsupported fields.'; continue;
            }
            $entity = $operation['entity'] ?? null; $action = $operation['action'] ?? null;
            if (! is_string($entity) || ! in_array($entity, self::ENTITIES, true) || ! $this->allowsEntity($token, $entity)) $errors[$path.'.entity'] = 'Entity is not permitted for this token.';
            if (! is_string($action) || ! in_array($action, self::ACTIONS, true)) $errors[$path.'.action'] = 'Unsupported operation action.';
            $operation['payload'] = $operation['payload'] ?? [];
            if (! is_array($operation['payload']) || array_is_list($operation['payload'])) $errors[$path.'.payload'] = 'Payload must be an object.';
            if ($action === 'create') {
                $ref = $operation['client_ref'] ?? null;
                if (! is_string($ref) || ! preg_match('/^[a-z][a-z0-9_-]{0,59}$/D', $ref) || isset($refs[$ref])) $errors[$path.'.client_ref'] = 'Create requires a unique client_ref.';
                else $refs[$ref] = ['entity' => $entity, 'index' => $index];
                if (isset($operation['target_id']) || isset($operation['expected_version'])) $errors[$path] = 'Create cannot provide target or expected version.';
            } else {
                if (! filter_var($operation['target_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) $errors[$path.'.target_id'] = 'Target ID is required.';
                if (! filter_var($operation['expected_version'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) $errors[$path.'.expected_version'] = 'Expected version is required.';
                if ($action === 'update' && ($operation['payload'] ?? []) === []) $errors[$path.'.payload'] = 'Update needs at least one field.';
            }
            if (isset($operation['parent_client_ref']) && (! is_string($operation['parent_client_ref']) || $entity !== 'catalog' || $action === 'archive' || $action === 'restore')) $errors[$path.'.parent_client_ref'] = 'Only catalog creates/updates can reference a proposed parent.';
            if (isset($operation['parent_client_ref']) && isset($operation['payload']['parent_id'])) $errors[$path.'.payload.parent_id'] = 'Use either parent_id or parent_client_ref, not both.';
            $this->validatePayload($entity, $action, is_array($operation['payload']) ? $operation['payload'] : [], $path, $errors, $operation);
            $this->validateSources($operation['sources'] ?? [], $path, $errors, $token);
        }
        unset($operation);
        foreach ($operations as $index => $operation) {
            if (isset($operation['parent_client_ref'])) {
                $reference = $refs[$operation['parent_client_ref']] ?? null;
                if ($reference === null || $reference['entity'] !== 'catalog' || $reference['index'] >= $index) {
                    $errors['operations.'.$index.'.parent_client_ref'] = 'Reference must point to an earlier catalog create operation in this batch.';
                }
            }
        }
        if ($errors !== []) throw ValidationException::withMessages($errors);
        return $operations;
    }

    /** @param array<string, mixed> $payload @param array<string, array<int, string>> $errors @param array<string, mixed> $operation */
    private function validatePayload(?string $entity, ?string $action, array $payload, string $path, array &$errors, array $operation): void
    {
        if ($entity === null || ! in_array($entity, self::ENTITIES, true) || ! in_array($action, self::ACTIONS, true)) return;
        $allowed = match ($entity) {
            'catalog' => ['category_id', 'item_type', 'record_kind', 'parent_id', 'code', 'name_th', 'name_en', 'description_th', 'description_en', 'price', 'sale_price', 'currency', 'transaction_type', 'availability', 'duration_minutes', 'terms', 'keywords', 'location_text', 'province', 'district', 'subdistrict', 'project_name', 'primary_image_url', 'bedrooms', 'bathrooms', 'usable_area_sqm', 'land_area_sqw', 'floor', 'attributes', 'effective_from', 'effective_until'],
            'faq' => ['question_th', 'answer_th', 'question_en', 'answer_en', 'category', 'tags'],
            'knowledge' => ['title', 'body', 'type', 'category', 'tags', 'source_url'],
        };
        if ($entity === 'catalog') $allowed = [...$allowed, 'profile', 'profile_data', 'map_url'];
        foreach (array_keys($payload) as $field) if (! in_array($field, $allowed, true)) $errors[$path.'.payload.'.$field] = 'This field is not writable by an agent.';
        if (in_array($action, ['archive', 'restore'], true) && $payload !== []) $errors[$path.'.payload'] = 'Archive/restore cannot include data fields.';
        $rules = match ($entity) {
            'catalog' => ['category_id' => ['nullable','integer','exists:package_categories,id'], 'item_type' => ['sometimes','string','max:40','regex:/^[a-z0-9_-]+$/'], 'record_kind' => ['sometimes', Rule::in(['group','variant','offer'])], 'parent_id' => ['nullable','integer','exists:packages,id'], 'code' => ['nullable','string','max:60'], 'name_th' => [$action === 'create' ? 'required' : 'sometimes','string','max:255'], 'name_en' => ['nullable','string','max:255'], 'description_th' => ['nullable','string','max:5000'], 'description_en' => ['nullable','string','max:5000'], 'price' => ['nullable','numeric','min:0','max:1000000000'], 'sale_price' => ['nullable','numeric','min:0','max:1000000000'], 'currency' => ['nullable','string','size:3'], 'transaction_type' => ['nullable',Rule::in(['sale','rent','service'])], 'availability' => ['nullable',Rule::in(['unknown','available','reserved','sold','rented','unavailable'])], 'duration_minutes' => ['nullable','integer','min:0','max:1000000'], 'terms' => ['nullable','string','max:5000'], 'keywords' => ['nullable','string','max:1000'], 'location_text' => ['nullable','string','max:255'], 'province' => ['nullable','string','max:100'], 'district' => ['nullable','string','max:100'], 'subdistrict' => ['nullable','string','max:100'], 'project_name' => ['nullable','string','max:255'], 'primary_image_url' => ['nullable','url:https','max:2048'], 'bedrooms' => ['nullable','integer','min:0','max:99'], 'bathrooms' => ['nullable','integer','min:0','max:99'], 'usable_area_sqm' => ['nullable','numeric','min:0','max:1000000'], 'land_area_sqw' => ['nullable','numeric','min:0','max:1000000'], 'floor' => ['nullable','integer','min:0','max:999'], 'attributes' => ['nullable','array','max:40'], 'effective_from' => ['nullable','date'], 'effective_until' => ['nullable','date','after_or_equal:effective_from']],
            'faq' => ['question_th' => [$action === 'create' ? 'required' : 'sometimes','string','max:2000'], 'answer_th' => [$action === 'create' ? 'required' : 'sometimes','string','max:5000'], 'question_en' => ['nullable','string','max:2000'], 'answer_en' => ['nullable','string','max:5000'], 'category' => ['nullable','string','max:255'], 'tags' => ['nullable','string','max:500']],
            'knowledge' => ['title' => [$action === 'create' ? 'required' : 'sometimes','string','max:255'], 'body' => [$action === 'create' ? 'required' : 'sometimes','string','max:20000'], 'type' => [$action === 'create' ? 'required' : 'sometimes','string','max:50'], 'category' => ['nullable','string','max:255'], 'tags' => ['nullable','string','max:500'], 'source_url' => ['nullable','url','max:500']],
        };
        if ($entity === 'catalog') $rules['map_url'] = ['nullable', 'url:https', 'max:2048'];
        $validator = Validator::make($payload, $rules);
        if ($validator->fails()) foreach ($validator->errors()->toArray() as $field => $messages) $errors[$path.'.payload.'.$field] = $messages[0];
        if ($entity === 'catalog') {
            $existing = isset($operation['target_id']) && is_numeric($operation['target_id'])
                ? ServicePackage::find($operation['target_id'])?->toArray() ?? [] : [];
            try {
                app(CatalogProfiles::class)->validate(array_replace($existing, $payload));
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) $errors[$path.'.payload.'.$field] = $messages[0];
            }
        }
        if ($entity === 'catalog' && isset($payload['attributes']) && is_array($payload['attributes'])) {
            $categoryId = $payload['category_id'] ?? null;
            if ($categoryId === null && isset($operation['target_id'])) $categoryId = ServicePackage::query()->find($operation['target_id'])?->category_id;
            try { app(AttributeValidator::class)->values($payload['attributes'], $categoryId ? PackageCategory::find($categoryId) : null); }
            catch (ValidationException $exception) { foreach ($exception->errors() as $field => $messages) $errors[$path.'.payload.'.$field] = $messages[0]; }
        }
    }

    /** @param mixed $sources @param array<string, array<int, string>> $errors */
    private function validateSources(mixed $sources, string $path, array &$errors, ApiToken $token): void
    {
        if ($sources === null) return;
        if (! is_array($sources) || ! array_is_list($sources) || count($sources) > 40) { $errors[$path.'.sources'] = 'Sources must be a list of at most 40 records.'; return; }
        foreach ($sources as $index => $source) {
            $sourcePath = $path.'.sources.'.$index;
            if (! is_array($source) || array_diff(array_keys($source), ['document_id','external_label','page','field_paths'])) { $errors[$sourcePath] = 'Source contains unsupported fields.'; continue; }
            $hasDocument = isset($source['document_id']); $hasExternal = isset($source['external_label']);
            if ($hasDocument === $hasExternal) $errors[$sourcePath] = 'Provide exactly one document_id or external_label.';
            if ($hasDocument) {
                $document = filter_var($source['document_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                    ? DocumentSource::query()->find($source['document_id'])
                    : null;
                if ($document === null) $errors[$sourcePath.'.document_id'] = 'Document source does not exist.';
                elseif ($document->isCancelled()) $errors[$sourcePath.'.document_id'] = 'Cancelled documents cannot be used as evidence.';
                elseif ($token->user_id === null || $document->user_id === null || (int) $document->user_id !== (int) $token->user_id) {
                    $errors[$sourcePath.'.document_id'] = 'Document source does not belong to this agent key issuer.';
                }
            }
            if ($hasExternal && (! is_string($source['external_label']) || trim($source['external_label']) === '' || mb_strlen($source['external_label']) > 255)) $errors[$sourcePath.'.external_label'] = 'External reference label is invalid.';
            if (isset($source['page']) && (! filter_var($source['page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]))) $errors[$sourcePath.'.page'] = 'Page must be 1–10000.';
            if (isset($source['field_paths']) && (! is_array($source['field_paths']) || ! array_is_list($source['field_paths']) || count($source['field_paths']) > 40 || collect($source['field_paths'])->contains(fn ($value) => ! is_string($value) || mb_strlen($value) > 120))) $errors[$sourcePath.'.field_paths'] = 'Field paths must be a bounded string list.';
        }
    }

    /** @param array<string, mixed> $operation @return array<string, mixed> */
    private function previewOperation(array $operation): array
    {
        $result = ['entity' => $operation['entity'], 'action' => $operation['action'], 'target_id' => $operation['target_id'] ?? null, 'client_ref' => $operation['client_ref'] ?? null, 'changes' => array_keys($operation['payload'] ?? [])];
        if (($operation['action'] ?? null) === 'create') $result['impact'] = 'Creates an unpublished/inactive draft after admin approval and apply.';
        if (($operation['action'] ?? null) === 'update') $result['impact'] = 'Requires the expected current record version at apply time.';
        if (($operation['action'] ?? null) === 'archive') $result['impact'] = 'Recoverable archive; public retrieval excludes archived records.';
        return $result;
    }

    /** @param array<int, array<string, mixed>> $operations @return array<string, int> */
    private function schemaVersions(array $operations): array
    {
        $categories = [];
        foreach ($operations as $operation) {
            if ($operation['entity'] === 'catalog') {
                $categoryId = $operation['payload']['category_id'] ?? null;
                if ($categoryId === null && isset($operation['target_id'])) {
                    $categoryId = ServicePackage::query()->find($operation['target_id'])?->category_id;
                }
                $category = $categoryId ? PackageCategory::find($categoryId) : null;
                if ($category) $categories[(string) $category->id] = $category->schema_version;
                $profile = array_key_exists('profile', $operation['payload'] ?? [])
                    ? $operation['payload']['profile']
                    : (isset($operation['target_id']) ? ServicePackage::find($operation['target_id'])?->profile : null);
                if (is_string($profile)) {
                    $categories['profile:'.$profile] = app(CatalogProfiles::class)->schemas()[$profile]['version'];
                }
            }
        }
        return $categories;
    }

    private function allowsEntity(ApiToken $token, string $entity): bool
    {
        $abilities = $token->abilities ?? [];
        return in_array('*', $abilities, true) || in_array('agent:'.$entity, $abilities, true);
    }

    private function assertEntityAllowed(ApiToken $token, string $entity): void
    {
        abort_unless(in_array($entity, self::ENTITIES, true) && $this->allowsEntity($token, $entity), 404);
    }

    /** @return array<string, mixed> */
    private function agentRecord(string $entity, Model $model): array
    {
        $fields = match ($entity) {
            'catalog' => ['id', 'category_id', 'item_type', 'record_kind', 'parent_id', 'code', 'name_th', 'name_en', 'description_th', 'description_en', 'price', 'sale_price', 'currency', 'transaction_type', 'availability', 'duration_minutes', 'terms', 'keywords', 'location_text', 'province', 'district', 'subdistrict', 'project_name', 'primary_image_url', 'bedrooms', 'bathrooms', 'usable_area_sqm', 'land_area_sqw', 'floor', 'attributes', 'effective_from', 'effective_until', 'is_active', 'is_published', 'lock_version', 'archived_at'],
            'faq' => ['id', 'question_th', 'answer_th', 'question_en', 'answer_en', 'category', 'tags', 'is_active', 'lock_version', 'archived_at'],
            'knowledge' => ['id', 'title', 'body', 'type', 'category', 'tags', 'source_url', 'version', 'is_active', 'reviewed_at', 'lock_version', 'archived_at'],
        };

        if ($entity === 'catalog') $fields = [...$fields, 'profile', 'profile_data', 'map_url'];
        return Arr::only($model->toArray(), $fields);
    }

    /** @param mixed $value */
    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) return $value;
        if (array_is_list($value)) return array_map(fn ($item) => $this->canonical($item), $value);
        ksort($value);
        foreach ($value as $key => $item) $value[$key] = $this->canonical($item);
        return $value;
    }

    /** @return array<string, mixed> */
    private function snapshot(Model $model): array
    {
        return Arr::except($model->toArray(), ['created_at', 'updated_at']);
    }
}
