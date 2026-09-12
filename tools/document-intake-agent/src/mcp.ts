import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { z } from 'zod';
import { ManagementClient, ManagementClientError } from './client.js';
import { ALLOWED_STATUSES } from './types.js';

const agentEntity = z.enum(['catalog', 'faq', 'knowledge']);
const proposalOperation = z.object({
  entity: agentEntity,
  action: z.enum(['create', 'update', 'archive', 'restore']),
  target_id: z.number().int().positive().optional(),
  client_ref: z.string().regex(/^[a-z][a-z0-9_-]{0,59}$/).optional(),
  parent_client_ref: z.string().regex(/^[a-z][a-z0-9_-]{0,59}$/).optional(),
  expected_version: z.number().int().positive().optional(),
  payload: z.record(z.unknown()).optional(),
  sources: z.array(z.object({
    document_id: z.number().int().positive().optional(),
    external_label: z.string().min(1).max(255).optional(),
    page: z.number().int().min(1).max(10000).optional(),
    field_paths: z.array(z.string().max(120)).max(40).optional(),
  }).strict()).max(40).optional(),
}).strict();

function errorResult(error: unknown, fallback: string) {
  const message = error instanceof ManagementClientError ? error.message : error instanceof Error ? error.message : fallback;
  return { isError: true, content: [{ type: 'text' as const, text: `Error: ${message}` }] };
}

export function createMcpServer(client?: ManagementClient): McpServer {
  const server = new McpServer({
    name: 'document-intake-agent',
    version: '0.2.0',
  });

  const getClient = (): ManagementClient => {
    return client ?? new ManagementClient();
  };

  server.tool(
    'document_list',
    'List safe metadata for document intake records with optional status filtering and bounded pagination.',
    {
      status: z
        .enum(ALLOWED_STATUSES)
        .optional()
        .describe(
          'Filter by document status: uploaded, extracting, ready, ocr_required, failed'
        ),
      limit: z
        .number()
        .int()
        .min(1)
        .max(50)
        .optional()
        .describe('Maximum number of records to return (1-50, default 15)'),
      page: z
        .number()
        .int()
        .min(1)
        .max(1000)
        .optional()
        .describe('Page number (1-1000, default 1)'),
    },
    async (args) => {
      try {
        const apiClient = getClient();
        const result = await apiClient.listDocuments({
          status: args.status,
          limit: args.limit,
          page: args.page,
        });

        return {
          content: [
            {
              type: 'text' as const,
              text: JSON.stringify(result, null, 2),
            },
          ],
        };
      } catch (err: unknown) {
        const message =
          err instanceof ManagementClientError
            ? err.message
            : err instanceof Error
              ? err.message
              : 'Failed to retrieve document list.';

        return {
          isError: true,
          content: [
            {
              type: 'text' as const,
              text: `Error: ${message}`,
            },
          ],
        };
      }
    }
  );

  server.tool(
    'document_get',
    'Retrieve safe allowlisted metadata for a single document intake record by integer ID.',
    {
      id: z
        .union([z.number().int().positive(), z.string().regex(/^[1-9]\d*$/)])
        .describe('Strictly positive integer document ID'),
    },
    async (args) => {
      try {
        const apiClient = getClient();
        const result = await apiClient.getDocument(args.id);

        return {
          content: [
            {
              type: 'text' as const,
              text: JSON.stringify(result, null, 2),
            },
          ],
        };
      } catch (err: unknown) {
        const message =
          err instanceof ManagementClientError
            ? err.message
            : err instanceof Error
              ? err.message
              : 'Failed to retrieve document metadata.';

        return {
          isError: true,
          content: [
            {
              type: 'text' as const,
              text: `Error: ${message}`,
            },
          ],
        };
      }
    }
  );

  server.tool(
    'agent_schema',
    'Read the scoped Management proposal schema before preparing a catalog, FAQ, or knowledge proposal. It exposes no SQL and cannot apply or publish.',
    {},
    async () => {
      try {
        const result = await getClient().getAgentSchema();
        return { content: [{ type: 'text' as const, text: JSON.stringify(result, null, 2) }] };
      } catch (error: unknown) { return errorResult(error, 'Failed to retrieve agent schema.'); }
    }
  );

  server.tool(
    'agent_records_search',
    'Search bounded, allowlisted records for a scoped proposal. Read the schema first; this is not a database query tool.',
    {
      entity: agentEntity,
      query: z.string().max(100).optional(),
      limit: z.number().int().min(1).max(50).optional(),
      include_archived: z.boolean().optional(),
      profile: z.string().regex(/^[a-z][a-z0-9_]{0,59}$/).optional().describe('Catalog profile from agent_schema.'),
      record_kind: z.enum(['group', 'variant', 'offer']).optional(),
      parent_id: z.number().int().positive().optional(),
      facility: z.string().regex(/^[a-z][a-z0-9_]{0,59}$/).optional().describe('Exact facility; requires profile=property_project.'),
    },
    async (args) => {
      try {
        const result = await getClient().listAgentRecords(args.entity, { query: args.query, limit: args.limit, includeArchived: args.include_archived,
          profile: args.profile, recordKind: args.record_kind, parentId: args.parent_id, facility: args.facility });
        return { content: [{ type: 'text' as const, text: JSON.stringify(result, null, 2) }] };
      } catch (error: unknown) { return errorResult(error, 'Failed to retrieve proposal records.'); }
    }
  );

  server.tool(
    'agent_record_get',
    'Retrieve one allowlisted proposal record and its lock_version before creating an update/archive/restore proposal.',
    { entity: agentEntity, id: z.number().int().positive() },
    async (args) => {
      try {
        const result = await getClient().getAgentRecord(args.entity, args.id);
        return { content: [{ type: 'text' as const, text: JSON.stringify(result, null, 2) }] };
      } catch (error: unknown) { return errorResult(error, 'Failed to retrieve proposal record.'); }
    }
  );

  server.tool(
    'agent_changes_preview',
    'Validate and preview a proposed create, update, archive, or restore batch. This never creates business records or a change set.',
    { operations: z.array(proposalOperation).min(1).max(50) },
    async (args) => {
      try {
        const result = await getClient().previewChanges(args.operations);
        return { content: [{ type: 'text' as const, text: JSON.stringify(result, null, 2) }] };
      } catch (error: unknown) { return errorResult(error, 'Failed to preview proposal.'); }
    }
  );

  server.tool(
    'agent_changes_submit',
    'Submit an immutable proposal for human review after preview. This cannot approve, apply, publish, or delete data.',
    { operations: z.array(proposalOperation).min(1).max(50), idempotency_key: z.string().regex(/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/) },
    async (args) => {
      try {
        const result = await getClient().submitChanges(args.operations, args.idempotency_key);
        return { content: [{ type: 'text' as const, text: JSON.stringify(result, null, 2) }] };
      } catch (error: unknown) { return errorResult(error, 'Failed to submit proposal.'); }
    }
  );

  server.tool(
    'agent_changes_get',
    'Read the status of a proposal created by this same agent key. Another key cannot inspect it.',
    { id: z.string().uuid() },
    async (args) => {
      try {
        const result = await getClient().getChangeSet(args.id);
        return { content: [{ type: 'text' as const, text: JSON.stringify(result, null, 2) }] };
      } catch (error: unknown) { return errorResult(error, 'Failed to retrieve proposal status.'); }
    }
  );

  return server;
}

export async function runMcpServer(): Promise<void> {
  const server = createMcpServer();
  const transport = new StdioServerTransport();
  await server.connect(transport);
}

// Execute directly if run as main
if (import.meta.url === `file://${process.argv[1]}`) {
  runMcpServer().catch((err) => {
    console.error(
      'Fatal MCP server error:',
      err instanceof Error ? err.message : 'Unknown error'
    );
    process.exit(1);
  });
}
