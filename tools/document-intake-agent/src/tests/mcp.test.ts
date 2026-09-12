import test from 'node:test';
import assert from 'node:assert/strict';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js';
import { createMcpServer } from '../mcp.js';
import { ManagementClient } from '../client.js';

test('MCP: tool listing exposes read tools plus proposal-only tools, never apply or publish', async () => {
  const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();

  const mockClient = new ManagementClient({
    baseUrl: 'http://localhost:8000',
    token: 'test-token',
  });

  const server = createMcpServer(mockClient);
  await server.connect(serverTransport);

  const mcpClient = new Client(
    { name: 'test-client', version: '1.0' },
    { capabilities: {} }
  );
  await mcpClient.connect(clientTransport);

  try {
    const listResult = await mcpClient.listTools();
    const toolNames = listResult.tools.map((t) => t.name).sort();

    assert.deepEqual(toolNames, [
      'agent_changes_get', 'agent_changes_preview', 'agent_changes_submit',
      'agent_record_get', 'agent_records_search', 'agent_schema',
      'document_get', 'document_list',
    ]);
    assert.equal(toolNames.some((name) => /apply|approve|publish|sql/i.test(name)), false);
    const searchTool = listResult.tools.find(t => t.name === 'agent_records_search');
    for (const field of ['profile', 'record_kind', 'parent_id', 'facility']) assert(searchTool?.inputSchema.properties?.[field]);

    const listTool = listResult.tools.find((t) => t.name === 'document_list');
    assert(listTool);
    assert.equal(listTool.inputSchema.type, 'object');
    assert(listTool.inputSchema.properties?.status);
    assert(listTool.inputSchema.properties?.limit);
    assert(listTool.inputSchema.properties?.page);

    const getTool = listResult.tools.find((t) => t.name === 'document_get');
    assert(getTool);
    assert.equal(getTool.inputSchema.type, 'object');
    assert(getTool.inputSchema.properties?.id);
  } finally {
    await mcpClient.close();
    await server.close();
  }
});

test('MCP: proposal preview is forwarded without a credential in tool output', async () => {
  const originalFetch = globalThis.fetch;
  try {
    globalThis.fetch = (async (input: string | URL | Request, init?: RequestInit) => {
      assert.match(String(input), /\/api\/v1\/agent\/changes\/preview$/);
      assert.equal(init?.method, 'POST');
      return new Response(JSON.stringify({ data: { operations: [{ entity: 'catalog', action: 'create' }], warnings: ['Admin review required'] } }), { status: 200 });
    }) as typeof fetch;
    const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();
    const server = createMcpServer(new ManagementClient({ baseUrl: 'http://localhost:8000', token: 'secret-proposal-token' }));
    await server.connect(serverTransport);
    const mcpClient = new Client({ name: 'test-client', version: '1.0' }, { capabilities: {} });
    await mcpClient.connect(clientTransport);
    try {
      const result = await mcpClient.callTool({ name: 'agent_changes_preview', arguments: { operations: [{ entity: 'catalog', action: 'create', client_ref: 'draft', payload: { name_th: 'Draft' } }] } });
      assert.equal(result.isError, undefined);
      const content = result.content as Array<{ text: string }>;
      const text = content[0].text;
      assert.match(text, /Admin review required/);
      assert.doesNotMatch(text, /secret-proposal-token/);
    } finally { await mcpClient.close(); await server.close(); }
  } finally { globalThis.fetch = originalFetch; }
});

test('MCP: executes document_list tool handler through protocol and returns safe metadata', async () => {
  const originalFetch = globalThis.fetch;

  try {
    globalThis.fetch = (async () => {
      return new Response(
        JSON.stringify({
          meta: {
            version: '1.0',
            count: 1,
            total: 1,
            current_page: 1,
            last_page: 1,
            per_page: 15,
            applied_filters: { status: 'ready' },
          },
          data: [
            {
              id: 1,
              source_type: 'upload',
              original_filename: 'mcp_test.pdf',
              mime_type: 'application/pdf',
              file_size: 5000,
              status: 'ready',
              page_count: 4,
              failure_category: null,
              created_at: '2026-09-04T12:00:00Z',
              updated_at: '2026-09-04T12:00:00Z',
              // Private internal attributes returned by unsafe upstream:
              storage_path: 'private/should/be/excluded.pdf',
              file_hash: 'raw-hash-value',
              failure_reason: 'internal_text',
            },
          ],
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } }
      );
    }) as typeof fetch;

    const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();

    const mockClient = new ManagementClient({
      baseUrl: 'http://localhost:8000',
      token: 'test-mcp-token',
    });

    const server = createMcpServer(mockClient);
    await server.connect(serverTransport);

    const mcpClient = new Client(
      { name: 'test-client', version: '1.0' },
      { capabilities: {} }
    );
    await mcpClient.connect(clientTransport);

    try {
      const result = await mcpClient.callTool({
        name: 'document_list',
        arguments: { status: 'ready', limit: 5, page: 1 },
      });

      assert.equal(result.isError, undefined);
      assert(Array.isArray(result.content));
      assert.equal(result.content.length, 1);

      const textContent = result.content[0];
      assert.equal(textContent.type, 'text');

      const parsed = JSON.parse((textContent as { text: string }).text);
      assert.equal(parsed.meta.version, '1.0');
      assert.equal(parsed.data.length, 1);
      assert.equal(parsed.data[0].id, 1);
      assert.equal(parsed.data[0].original_filename, 'mcp_test.pdf');
      assert.equal(parsed.data[0].failure_category, null);

      // Verify strict exclusion
      assert.equal(parsed.data[0].failure_reason, undefined);
      assert.equal(parsed.data[0].storage_path, undefined);
      assert.equal(parsed.data[0].file_hash, undefined);
    } finally {
      await mcpClient.close();
      await server.close();
    }
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test('MCP: executes document_get tool handler through protocol and returns single document metadata', async () => {
  const originalFetch = globalThis.fetch;

  try {
    globalThis.fetch = (async () => {
      return new Response(
        JSON.stringify({
          meta: { version: '1.0' },
          data: {
            id: 99,
            source_type: 'upload',
            original_filename: 'detail_mcp.pdf',
            mime_type: 'application/pdf',
            file_size: 1024,
            status: 'ready',
            page_count: 1,
            failure_category: null,
            created_at: '2026-09-04T12:00:00Z',
            updated_at: '2026-09-04T12:00:00Z',
            file_hash: 'secret-hash-value',
            failure_reason: 'secret_reason',
          },
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } }
      );
    }) as typeof fetch;

    const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();

    const mockClient = new ManagementClient({
      baseUrl: 'http://localhost:8000',
      token: 'test-mcp-token',
    });

    const server = createMcpServer(mockClient);
    await server.connect(serverTransport);

    const mcpClient = new Client(
      { name: 'test-client', version: '1.0' },
      { capabilities: {} }
    );
    await mcpClient.connect(clientTransport);

    try {
      const result = await mcpClient.callTool({
        name: 'document_get',
        arguments: { id: 99 },
      });

      assert.equal(result.isError, undefined);
      assert(Array.isArray(result.content));
      assert.equal(result.content.length, 1);

      const parsed = JSON.parse(
        (result.content[0] as { text: string }).text
      );
      assert.equal(parsed.data.id, 99);
      assert.equal(parsed.data.original_filename, 'detail_mcp.pdf');
      assert.equal(parsed.data.file_hash, undefined);
      assert.equal(parsed.data.failure_reason, undefined);
    } finally {
      await mcpClient.close();
      await server.close();
    }
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test('MCP: document_get handles 404 by returning isError: true without credential leak', async () => {
  const secretToken = 'ultra-secret-mcp-token-999';
  const originalFetch = globalThis.fetch;

  try {
    globalThis.fetch = (async () => {
      return new Response(JSON.stringify({ message: 'Document not found.' }), {
        status: 404,
        headers: { 'Content-Type': 'application/json' },
      });
    }) as typeof fetch;

    const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();

    const mockClient = new ManagementClient({
      baseUrl: 'http://localhost:8000',
      token: secretToken,
    });

    const server = createMcpServer(mockClient);
    await server.connect(serverTransport);

    const mcpClient = new Client(
      { name: 'test-client', version: '1.0' },
      { capabilities: {} }
    );
    await mcpClient.connect(clientTransport);

    try {
      const result = await mcpClient.callTool({
        name: 'document_get',
        arguments: { id: 9999 },
      });

      assert.equal(result.isError, true);
      assert(Array.isArray(result.content));
      const text = (result.content[0] as { text: string }).text;
      assert.match(text, /Document not found/);
      assert.equal(text.includes(secretToken), false);
    } finally {
      await mcpClient.close();
      await server.close();
    }
  } finally {
    globalThis.fetch = originalFetch;
  }
});
