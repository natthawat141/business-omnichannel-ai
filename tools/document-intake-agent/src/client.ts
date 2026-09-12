import {
  ALLOWED_FAILURE_CATEGORIES,
  ALLOWED_STATUSES,
  DocumentSourceMetadata,
  DocumentStatus,
  FailureCategory,
  GetDocumentResponse,
  ListDocumentsOptions,
  ListDocumentsResponse,
  AGENT_ENTITIES,
  AgentEntity,
  AgentRecordListResponse,
  AgentRecordResponse,
  AgentSchemaResponse,
  ChangeSetResponse,
  PreviewResponse,
  ProposalOperation,
} from './types.js';

export class ManagementClientError extends Error {
  public readonly statusCode?: number;

  constructor(message: string, statusCode?: number) {
    super(message);
    this.name = 'ManagementClientError';
    this.statusCode = statusCode;
    Object.setPrototypeOf(this, new.target.prototype);
  }
}

export interface ManagementClientConfig {
  baseUrl?: string;
  token?: string;
}

export class ManagementClient {
  private readonly baseUrl: string;
  private readonly token: string;

  constructor(config: ManagementClientConfig = {}) {
    const rawUrl = config.baseUrl ?? process.env.MANAGEMENT_API_BASE_URL;
    const rawToken = config.token ?? process.env.MANAGEMENT_API_TOKEN;

    if (!rawUrl || typeof rawUrl !== 'string' || rawUrl.trim() === '') {
      throw new ManagementClientError(
        'MANAGEMENT_API_BASE_URL is required and must be a valid URL.'
      );
    }

    if (!rawToken || typeof rawToken !== 'string' || rawToken.trim() === '') {
      throw new ManagementClientError(
        'MANAGEMENT_API_TOKEN is required and cannot be empty.'
      );
    }

    let parsed: URL;
    try {
      parsed = new URL(rawUrl.trim());
    } catch {
      throw new ManagementClientError('MANAGEMENT_API_BASE_URL is not a valid URL.');
    }

    // Strictly permit only http: or https:
    if (parsed.protocol !== 'https:' && parsed.protocol !== 'http:') {
      throw new ManagementClientError(
        `Disallowed URL protocol '${parsed.protocol}'. MANAGEMENT_API_BASE_URL must use https: (or http: for localhost development only).`
      );
    }

    const hostname = parsed.hostname.toLowerCase();
    const isLocalhost =
      hostname === 'localhost' ||
      hostname === '127.0.0.1' ||
      hostname === '[::1]' ||
      hostname === '::1';

    if (parsed.protocol === 'http:' && !isLocalhost) {
      throw new ManagementClientError(
        'MANAGEMENT_API_BASE_URL must use HTTPS unless connecting to localhost.'
      );
    }

    // Strip trailing slashes to avoid double-slash or base-path corruption
    this.baseUrl = parsed.origin + parsed.pathname.replace(/\/+$/, '');
    this.token = rawToken.trim();
  }

  /**
   * List document intake records with optional filtering and bounded pagination.
   */
  public async listDocuments(
    options: ListDocumentsOptions = {}
  ): Promise<ListDocumentsResponse> {
    const params = new URLSearchParams();

    if (options.status !== undefined && options.status !== null) {
      const statusStr = String(options.status).trim();
      if (!ALLOWED_STATUSES.includes(statusStr as DocumentStatus)) {
        throw new ManagementClientError(
          `Invalid status '${statusStr}'. Allowed statuses: ${ALLOWED_STATUSES.join(', ')}.`
        );
      }
      params.set('status', statusStr);
    }

    if (options.limit !== undefined && options.limit !== null) {
      const limitNum = Number(options.limit);
      if (!Number.isInteger(limitNum) || limitNum < 1 || limitNum > 50) {
        throw new ManagementClientError(
          'Limit must be an integer between 1 and 50.'
        );
      }
      params.set('limit', String(limitNum));
    }

    if (options.page !== undefined && options.page !== null) {
      const pageNum = Number(options.page);
      if (!Number.isInteger(pageNum) || pageNum < 1 || pageNum > 1000) {
        throw new ManagementClientError(
          'Page must be an integer between 1 and 1000.'
        );
      }
      params.set('page', String(pageNum));
    }

    const queryString = params.toString();
    const endpoint = `/api/v1/documents${queryString ? `?${queryString}` : ''}`;
    const raw = await this.fetchJson(endpoint);

    return this.sanitizeListResponse(raw);
  }

  /**
   * Get safe allowlisted metadata for a single document intake record.
   */
  public async getDocument(id: number | string): Promise<GetDocumentResponse> {
    const idStr = String(id);
    if (!/^[1-9]\d*$/.test(idStr)) {
      throw new ManagementClientError(
        'Document ID must be a strictly positive integer.'
      );
    }

    const endpoint = `/api/v1/documents/${encodeURIComponent(idStr)}`;
    const raw = await this.fetchJson(endpoint);

    return this.sanitizeGetResponse(raw);
  }

  /** Read the bounded proposal schema. It never exposes database/SQL details. */
  public async getAgentSchema(): Promise<AgentSchemaResponse> {
    return this.sanitizeAgentSchema(await this.fetchJson('/api/v1/agent/schema'));
  }

  /** Search only permitted records for a scoped proposal key. */
  public async listAgentRecords(
    entity: AgentEntity,
    options: { query?: string; limit?: number; includeArchived?: boolean; profile?: string; recordKind?: string; parentId?: number; facility?: string } = {}
  ): Promise<AgentRecordListResponse> {
    this.assertAgentEntity(entity);
    const params = new URLSearchParams();
    if (options.query !== undefined) {
      if (typeof options.query !== 'string' || options.query.length > 100) {
        throw new ManagementClientError('Record query must be a string of at most 100 characters.');
      }
      params.set('query', options.query);
    }
    if (options.limit !== undefined) {
      if (!Number.isInteger(options.limit) || options.limit < 1 || options.limit > 50) {
        throw new ManagementClientError('Record limit must be an integer between 1 and 50.');
      }
      params.set('limit', String(options.limit));
    }
    if (options.includeArchived !== undefined) params.set('include_archived', options.includeArchived ? '1' : '0');
    for (const [name, value] of Object.entries({ profile: options.profile, record_kind: options.recordKind, facility: options.facility })) {
      if (value === undefined) continue;
      if (entity !== 'catalog' || typeof value !== 'string' || !/^[a-z][a-z0-9_]{0,59}$/.test(value)) {
        throw new ManagementClientError(`${name} must be a catalog schema identifier.`);
      }
      params.set(name, value);
    }
    if (options.parentId !== undefined) {
      if (entity !== 'catalog' || !Number.isSafeInteger(options.parentId) || options.parentId < 1) throw new ManagementClientError('parent_id must be a positive catalog ID.');
      params.set('parent_id', String(options.parentId));
    }
    const suffix = params.toString();
    return this.sanitizeAgentRecordList(await this.fetchJson(`/api/v1/agent/records/${entity}${suffix ? `?${suffix}` : ''}`), entity);
  }

  public async getAgentRecord(entity: AgentEntity, id: number | string): Promise<AgentRecordResponse> {
    this.assertAgentEntity(entity);
    const idString = String(id);
    if (!/^[1-9]\d*$/.test(idString)) throw new ManagementClientError('Record ID must be a strictly positive integer.');
    return this.sanitizeAgentRecord(await this.fetchJson(`/api/v1/agent/records/${entity}/${encodeURIComponent(idString)}`));
  }

  /** Server-side preview only; it does not create business records or a change set. */
  public async previewChanges(operations: ProposalOperation[]): Promise<PreviewResponse> {
    this.assertOperations(operations);
    return this.sanitizePreview(await this.fetchJson('/api/v1/agent/changes/preview', { method: 'POST', body: { operations } }));
  }

  /** Submit one immutable proposal. It can never approve, apply, or publish. */
  public async submitChanges(operations: ProposalOperation[], idempotencyKey: string): Promise<ChangeSetResponse> {
    this.assertOperations(operations);
    if (!/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/.test(idempotencyKey)) {
      throw new ManagementClientError('Idempotency key must be 8–128 safe characters.');
    }
    return this.sanitizeChangeSet(await this.fetchJson('/api/v1/agent/changes', {
      method: 'POST', body: { operations }, idempotencyKey,
    }));
  }

  public async getChangeSet(id: string): Promise<ChangeSetResponse> {
    if (!/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(id)) {
      throw new ManagementClientError('Change set ID must be a UUID.');
    }
    return this.sanitizeChangeSet(await this.fetchJson(`/api/v1/agent/changes/${encodeURIComponent(id)}`));
  }

  private async fetchJson(
    endpoint: string,
    options: { method?: 'GET' | 'POST'; body?: Record<string, unknown>; idempotencyKey?: string } = {}
  ): Promise<unknown> {
    const targetUrl = `${this.baseUrl}${endpoint}`;

    let response: Response;
    try {
      response = await fetch(targetUrl, {
        method: options.method ?? 'GET',
        headers: {
          Authorization: `Bearer ${this.token}`,
          Accept: 'application/json',
          ...(options.body ? { 'Content-Type': 'application/json' } : {}),
          ...(options.idempotencyKey ? { 'Idempotency-Key': options.idempotencyKey } : {}),
        },
        body: options.body ? JSON.stringify(options.body) : undefined,
        signal: AbortSignal.timeout(5000),
      });
    } catch (err: unknown) {
      if (err instanceof Error) {
        if (err.name === 'TimeoutError' || err.name === 'AbortError') {
          throw new ManagementClientError('Management API request timed out after 5 seconds.');
        }
        // Redact all possible token occurrences from network error messages
        const safeMsg = err.message.replaceAll(this.token, '[REDACTED]');
        throw new ManagementClientError(`Network error communicating with Management API: ${safeMsg}`);
      }
      throw new ManagementClientError('Unknown network error communicating with Management API.');
    }

    if (!response.ok) {
      const status = response.status;
      if (status === 401) {
        throw new ManagementClientError('Authentication failed: invalid or expired API token.', 401);
      }
      if (status === 403) {
        throw new ManagementClientError(endpoint.startsWith('/api/v1/documents') ? 'Forbidden: API token lacks required documents:read ability.' : 'Forbidden: API token lacks the required scoped ability.', 403);
      }
      if (status === 404) {
        throw new ManagementClientError(endpoint.startsWith('/api/v1/documents') ? 'Document not found.' : 'Requested agent resource was not found.', 404);
      }
      if (status === 422) {
        throw new ManagementClientError('Validation error: request data was rejected.', 422);
      }
      throw new ManagementClientError(`Management API request failed with status ${status}.`, status);
    }

    let parsed: unknown;
    try {
      parsed = await response.json();
    } catch {
      throw new ManagementClientError('Management API returned a malformed response.');
    }

    return parsed;
  }

  private sanitizeListResponse(raw: unknown): ListDocumentsResponse {
    if (
      typeof raw !== 'object' ||
      raw === null ||
      !('meta' in raw) ||
      !('data' in raw) ||
      !Array.isArray((raw as { data: unknown }).data)
    ) {
      throw new ManagementClientError('Management API response envelope is malformed.');
    }

    const typed = raw as {
      meta: Record<string, unknown>;
      data: unknown[];
    };

    const sanitizedData = typed.data.map((item) => this.sanitizeItem(item));

    return {
      meta: {
        version: String(typed.meta.version ?? '1.0'),
        count: Number(typed.meta.count ?? sanitizedData.length),
        total: Number(typed.meta.total ?? sanitizedData.length),
        current_page: Number(typed.meta.current_page ?? 1),
        last_page: Number(typed.meta.last_page ?? 1),
        per_page: Number(typed.meta.per_page ?? sanitizedData.length),
        applied_filters: (typed.meta.applied_filters as { status?: string | null }) ?? {},
      },
      data: sanitizedData,
    };
  }

  private sanitizeGetResponse(raw: unknown): GetDocumentResponse {
    if (
      typeof raw !== 'object' ||
      raw === null ||
      !('meta' in raw) ||
      !('data' in raw) ||
      typeof (raw as { data: unknown }).data !== 'object' ||
      (raw as { data: unknown }).data === null
    ) {
      throw new ManagementClientError('Management API response envelope is malformed.');
    }

    const typed = raw as {
      meta: Record<string, unknown>;
      data: unknown;
    };

    return {
      meta: {
        version: String(typed.meta.version ?? '1.0'),
      },
      data: this.sanitizeItem(typed.data),
    };
  }

  private sanitizeItem(rawItem: unknown): DocumentSourceMetadata {
    if (typeof rawItem !== 'object' || rawItem === null || Array.isArray(rawItem)) {
      throw new ManagementClientError('Invalid document record: item must be an object.');
    }

    const item = rawItem as Record<string, unknown>;

    // 1. Positive integer id
    if (typeof item.id !== 'number' || !Number.isInteger(item.id) || item.id <= 0) {
      throw new ManagementClientError('Invalid document record: id must be a strictly positive integer.');
    }

    // 2. Non-empty string source_type
    if (typeof item.source_type !== 'string' || item.source_type.trim() === '') {
      throw new ManagementClientError('Invalid document record: source_type must be a non-empty string.');
    }

    // 3. Non-empty string original_filename
    if (typeof item.original_filename !== 'string' || item.original_filename.trim() === '') {
      throw new ManagementClientError('Invalid document record: original_filename must be a non-empty string.');
    }

    // 4. Non-empty string mime_type
    if (typeof item.mime_type !== 'string' || item.mime_type.trim() === '') {
      throw new ManagementClientError('Invalid document record: mime_type must be a non-empty string.');
    }

    // 5. Non-negative finite integer file_size
    if (
      typeof item.file_size !== 'number' ||
      !Number.isFinite(item.file_size) ||
      !Number.isInteger(item.file_size) ||
      item.file_size < 0
    ) {
      throw new ManagementClientError('Invalid document record: file_size must be a non-negative integer.');
    }

    // 6. Valid status in ALLOWED_STATUSES
    if (
      typeof item.status !== 'string' ||
      !ALLOWED_STATUSES.includes(item.status as DocumentStatus)
    ) {
      throw new ManagementClientError(`Invalid document record: status '${String(item.status)}' is not permitted.`);
    }

    // 7. page_count: positive integer or null
    if (
      item.page_count !== null &&
      (typeof item.page_count !== 'number' ||
        !Number.isInteger(item.page_count) ||
        item.page_count <= 0)
    ) {
      throw new ManagementClientError('Invalid document record: page_count must be null or a positive integer.');
    }

    // 8. failure_category: allowed failure category or null
    if (
      item.failure_category !== null &&
      (typeof item.failure_category !== 'string' ||
        !ALLOWED_FAILURE_CATEGORIES.includes(item.failure_category as FailureCategory))
    ) {
      throw new ManagementClientError('Invalid document record: failure_category must be null or a recognized failure category.');
    }

    // 9. created_at / updated_at: string or null
    if (item.created_at !== null && typeof item.created_at !== 'string') {
      throw new ManagementClientError('Invalid document record: created_at must be a string or null.');
    }
    if (item.updated_at !== null && typeof item.updated_at !== 'string') {
      throw new ManagementClientError('Invalid document record: updated_at must be a string or null.');
    }

    return {
      id: item.id,
      source_type: item.source_type,
      original_filename: item.original_filename,
      mime_type: item.mime_type,
      file_size: item.file_size,
      status: item.status as DocumentStatus,
      page_count: item.page_count,
      failure_category: item.failure_category as FailureCategory | null,
      created_at: item.created_at,
      updated_at: item.updated_at,
    };
  }

  private assertAgentEntity(entity: AgentEntity): void {
    if (!AGENT_ENTITIES.includes(entity)) throw new ManagementClientError('Agent entity is not supported.');
  }

  private assertOperations(operations: ProposalOperation[]): void {
    if (!Array.isArray(operations) || operations.length < 1 || operations.length > 50) {
      throw new ManagementClientError('A proposal requires between 1 and 50 operations.');
    }
    for (const operation of operations) {
      if (!operation || typeof operation !== 'object' || !AGENT_ENTITIES.includes(operation.entity) || !['create', 'update', 'archive', 'restore'].includes(operation.action)) {
        throw new ManagementClientError('Proposal contains an unsupported entity or action.');
      }
    }
  }

  private envelope(raw: unknown): { data: unknown } {
    if (typeof raw !== 'object' || raw === null || !('data' in raw)) {
      throw new ManagementClientError('Management API response envelope is malformed.');
    }
    return raw as { data: unknown };
  }

  private sanitizeAgentSchema(raw: unknown): AgentSchemaResponse {
    const data = this.envelope(raw).data;
    if (typeof data !== 'object' || data === null || !Array.isArray((data as { entities?: unknown }).entities)) throw new ManagementClientError('Agent schema response is malformed.');
    return { data: data as AgentSchemaResponse['data'] };
  }

  private sanitizeAgentRecordList(raw: unknown, entity: AgentEntity): AgentRecordListResponse {
    const data = this.envelope(raw).data;
    if (typeof data !== 'object' || data === null || (data as { entity?: unknown }).entity !== entity || !Array.isArray((data as { items?: unknown }).items)) throw new ManagementClientError('Agent record list response is malformed.');
    return { data: data as AgentRecordListResponse['data'] };
  }

  private sanitizeAgentRecord(raw: unknown): AgentRecordResponse {
    const data = this.envelope(raw).data;
    if (typeof data !== 'object' || data === null || Array.isArray(data)) throw new ManagementClientError('Agent record response is malformed.');
    return { data: data as Record<string, unknown> };
  }

  private sanitizePreview(raw: unknown): PreviewResponse {
    const data = this.envelope(raw).data;
    if (typeof data !== 'object' || data === null || !Array.isArray((data as { operations?: unknown }).operations) || !Array.isArray((data as { warnings?: unknown }).warnings)) throw new ManagementClientError('Proposal preview response is malformed.');
    return { data: data as PreviewResponse['data'] };
  }

  private sanitizeChangeSet(raw: unknown): ChangeSetResponse {
    const data = this.envelope(raw).data;
    if (typeof data !== 'object' || data === null || typeof (data as { id?: unknown }).id !== 'string' || typeof (data as { status?: unknown }).status !== 'string' || !Array.isArray((data as { operations?: unknown }).operations)) throw new ManagementClientError('Change set response is malformed.');
    return { data: data as ChangeSetResponse['data'] };
  }
}
