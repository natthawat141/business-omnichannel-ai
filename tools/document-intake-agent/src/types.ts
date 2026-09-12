export const ALLOWED_STATUSES = [
  'uploaded',
  'extracting',
  'ready',
  'ocr_required',
  'failed',
] as const;

export type DocumentStatus = (typeof ALLOWED_STATUSES)[number];

export const ALLOWED_FAILURE_CATEGORIES = [
  'invalid_pdf',
  'empty_file',
  'oversized',
  'security_rejected',
  'processing_error',
] as const;

export type FailureCategory = (typeof ALLOWED_FAILURE_CATEGORIES)[number];

export interface DocumentSourceMetadata {
  id: number;
  source_type: string;
  original_filename: string;
  mime_type: string;
  file_size: number;
  status: DocumentStatus;
  page_count: number | null;
  failure_category: FailureCategory | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface ListDocumentsMeta {
  version: string;
  count: number;
  total: number;
  current_page: number;
  last_page: number;
  per_page: number;
  applied_filters: {
    status?: string | null;
  };
}

export interface ListDocumentsResponse {
  meta: ListDocumentsMeta;
  data: DocumentSourceMetadata[];
}

export interface GetDocumentResponse {
  meta: {
    version: string;
    catalog_profiles?: Record<string, { version: number; record_kind: string; fields: Record<string, unknown> }>;
    catalog_profile_rules?: Record<string, unknown>;
  };
  data: DocumentSourceMetadata;
}

export interface ListDocumentsOptions {
  status?: string;
  limit?: number;
  page?: number;
}

/** Proposal API contracts. They intentionally model records, not tables/SQL. */
export const AGENT_ENTITIES = ['catalog', 'faq', 'knowledge'] as const;
export type AgentEntity = (typeof AGENT_ENTITIES)[number];
export type AgentAction = 'create' | 'update' | 'archive' | 'restore';

export interface AgentSchemaResponse {
  data: {
    version: string;
    entities: Array<{ name: AgentEntity; actions: AgentAction[]; create_defaults: Record<string, unknown> }>;
    limits: { operations_per_change_set: number; json_bytes: number };
    rules: { no_sql: boolean; agent_cannot_apply_or_publish: boolean; sources_are_reference_only: boolean };
  };
}

export interface AgentRecordListResponse {
  data: { entity: AgentEntity; items: Array<Record<string, unknown>>; limit: number };
}

export interface AgentRecordResponse { data: Record<string, unknown>; }

export interface ProposalSource {
  document_id?: number;
  external_label?: string;
  page?: number;
  field_paths?: string[];
}

export interface ProposalOperation {
  entity: AgentEntity;
  action: AgentAction;
  target_id?: number;
  client_ref?: string;
  parent_client_ref?: string;
  expected_version?: number;
  payload?: Record<string, unknown>;
  sources?: ProposalSource[];
}

export interface ChangeSetResponse {
  data: {
    id: string;
    status: string;
    operation_count: number;
    operations: Array<Record<string, unknown>>;
    [key: string]: unknown;
  };
}

export interface PreviewResponse {
  data: { operations: Array<Record<string, unknown>>; warnings: string[] };
}
