import test from 'node:test';
import assert from 'node:assert/strict';
import { ManagementClient, ManagementClientError } from '../client.js';

test('ManagementClient: forwards exact catalog profile filters and preserves schema', async () => {
  const original = globalThis.fetch;
  const schema = { entities: [], catalog_profiles: { property_project: { version: 1, record_kind: 'group', fields: { facilities: { type: 'enum_list', values: ['pool'] } } } } };
  try {
    globalThis.fetch = (async (input: string | URL | Request) => {
      const url = new URL(String(input));
      if (url.pathname.endsWith('/schema')) return new Response(JSON.stringify({ data: schema }));
      assert.equal(url.searchParams.get('profile'), 'property_project');
      assert.equal(url.searchParams.get('facility'), 'pool');
      assert.equal(url.searchParams.get('record_kind'), 'group');
      assert.equal(url.searchParams.get('parent_id'), '42');
      return new Response(JSON.stringify({ data: { entity: 'catalog', items: [], limit: 10 } }));
    }) as typeof fetch;
    const client = new ManagementClient({ baseUrl: 'https://example.com', token: 'synthetic-test-key' });
    assert.deepEqual((await client.getAgentSchema()).data, schema);
    assert.deepEqual((await client.listAgentRecords('catalog', { profile: 'property_project', facility: 'pool', recordKind: 'group', parentId: 42 })).data.items, []);
    await assert.rejects(client.listAgentRecords('faq', { profile: 'property_project' }), ManagementClientError);
    await assert.rejects(client.listAgentRecords('catalog', { parentId: 0 }), ManagementClientError);
    await assert.rejects(client.listAgentRecords('catalog', { profile: 'bad/name' }), ManagementClientError);
  } finally { globalThis.fetch = original; }
});

test('ManagementClient: requires baseUrl and token', () => {
  assert.throws(
    () => new ManagementClient({ baseUrl: '', token: 'valid-token' }),
    (err: unknown) => {
      assert(err instanceof ManagementClientError);
      assert.match(err.message, /MANAGEMENT_API_BASE_URL is required/);
      return true;
    }
  );

  assert.throws(
    () => new ManagementClient({ baseUrl: 'http://localhost:8000', token: '' }),
    (err: unknown) => {
      assert(err instanceof ManagementClientError);
      assert.match(err.message, /MANAGEMENT_API_TOKEN is required/);
      return true;
    }
  );
});

test('ManagementClient: rejects non-http/https protocols even on localhost', () => {
  const disallowedUrls = [
    'file://localhost/etc/passwd',
    'file:///var/log',
    'ftp://localhost:21',
    'ws://localhost:8000',
    'gopher://localhost:70',
  ];

  for (const url of disallowedUrls) {
    assert.throws(
      () => new ManagementClient({ baseUrl: url, token: 'test-token' }),
      (err: unknown) => {
        assert(err instanceof ManagementClientError);
        assert.match(err.message, /Disallowed URL protocol/);
        return true;
      }
    );
  }
});

test('ManagementClient: rejects non-HTTPS URLs on non-localhost hosts', () => {
  assert.throws(
    () =>
      new ManagementClient({
        baseUrl: 'http://example.com',
        token: 'secret-token-123',
      }),
    (err: unknown) => {
      assert(err instanceof ManagementClientError);
      assert.match(err.message, /must use HTTPS unless connecting to localhost/);
      return true;
    }
  );

  assert.throws(
    () =>
      new ManagementClient({
        baseUrl: 'http://192.168.1.50:8000',
        token: 'secret-token-123',
      }),
    (err: unknown) => {
      assert(err instanceof ManagementClientError);
      assert.match(err.message, /must use HTTPS unless connecting to localhost/);
      return true;
    }
  );
});

test('ManagementClient: accepts HTTP on localhost, 127.0.0.1, and ::1', () => {
  assert.doesNotThrow(
    () =>
      new ManagementClient({
        baseUrl: 'http://localhost:8000',
        token: 'secret-token-123',
      })
  );

  assert.doesNotThrow(
    () =>
      new ManagementClient({
        baseUrl: 'http://127.0.0.1:8000',
        token: 'secret-token-123',
      })
  );

  assert.doesNotThrow(
    () =>
      new ManagementClient({
        baseUrl: 'http://[::1]:8000',
        token: 'secret-token-123',
      })
  );
});

test('ManagementClient: accepts HTTPS on production/remote hosts', () => {
  assert.doesNotThrow(
    () =>
      new ManagementClient({
        baseUrl: 'https://management.example.com',
        token: 'secret-token-123',
      })
  );
});

test('ManagementClient: rejects invalid document IDs for getDocument', async () => {
  const client = new ManagementClient({
    baseUrl: 'http://localhost:8000',
    token: 'test-token',
  });

  const invalidIds = ['0', '-1', 'abc', '../etc/passwd', '1.5', '', ' 2 '];

  for (const id of invalidIds) {
    await assert.rejects(
      () => client.getDocument(id),
      (err: unknown) => {
        assert(err instanceof ManagementClientError);
        assert.match(err.message, /strictly positive integer/);
        return true;
      }
    );
  }
});

test('ManagementClient: validates listDocuments options and page bounds', async () => {
  const client = new ManagementClient({
    baseUrl: 'http://localhost:8000',
    token: 'test-token',
  });

  // Invalid status
  await assert.rejects(
    () => client.listDocuments({ status: 'cancelled' }),
    (err: unknown) => {
      assert(err instanceof ManagementClientError);
      assert.match(err.message, /Invalid status 'cancelled'/);
      return true;
    }
  );

  // Invalid limit
  await assert.rejects(
    () => client.listDocuments({ limit: 0 }),
    (err: unknown) => {
      assert(err instanceof ManagementClientError);
      assert.match(err.message, /Limit must be an integer between 1 and 50/);
      return true;
    }
  );

  await assert.rejects(
    () => client.listDocuments({ limit: 51 }),
    (err: unknown) => {
      assert(err instanceof ManagementClientError);
      assert.match(err.message, /Limit must be an integer between 1 and 50/);
      return true;
    }
  );

  // Invalid page: below 1
  await assert.rejects(
    () => client.listDocuments({ page: 0 }),
    (err: unknown) => {
      assert(err instanceof ManagementClientError);
      assert.match(err.message, /Page must be an integer between 1 and 1000/);
      return true;
    }
  );

  // Invalid page: above 1000
  await assert.rejects(
    () => client.listDocuments({ page: 1001 }),
    (err: unknown) => {
      assert(err instanceof ManagementClientError);
      assert.match(err.message, /Page must be an integer between 1 and 1000/);
      return true;
    }
  );
});

test('ManagementClient: listDocuments returns safe metadata, failure_category only, and redacts private fields', async () => {
  const originalFetch = globalThis.fetch;

  try {
    globalThis.fetch = (async (input: string | URL | Request, init?: RequestInit) => {
      assert.equal(
        init?.headers && (init.headers as Record<string, string>)['Authorization'],
        'Bearer super-secret-token'
      );
      assert.match(String(input), /\/api\/v1\/documents\?status=ready&limit=10&page=2/);

      return new Response(
        JSON.stringify({
          meta: {
            version: '1.0',
            count: 1,
            total: 1,
            current_page: 2,
            last_page: 2,
            per_page: 10,
            applied_filters: { status: 'ready' },
          },
          data: [
            {
              id: 42,
              source_type: 'upload',
              original_filename: 'safe_document.pdf',
              mime_type: 'application/pdf',
              file_size: 1048576,
              status: 'ready',
              page_count: 8,
              failure_category: null,
              created_at: '2026-09-04T12:00:00Z',
              updated_at: '2026-09-04T12:05:00Z',
              // Private internal attributes returned by unsafe upstream:
              storage_path: '/var/storage/private/secret.pdf',
              storage_disk: 'local',
              file_hash: '3f78c9ac8...hash',
              meta: { raw_db_blob: true },
              user_id: 1,
              user: { name: 'admin' },
              failure_reason: 'raw internal text that must not leak',
            },
          ],
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } }
      );
    }) as typeof fetch;

    const client = new ManagementClient({
      baseUrl: 'http://localhost:8000',
      token: 'super-secret-token',
    });

    const response = await client.listDocuments({
      status: 'ready',
      limit: 10,
      page: 2,
    });

    assert.equal(response.meta.version, '1.0');
    assert.equal(response.meta.count, 1);
    assert.equal(response.data.length, 1);

    const doc = response.data[0];
    assert.equal(doc.id, 42);
    assert.equal(doc.original_filename, 'safe_document.pdf');
    assert.equal(doc.status, 'ready');
    assert.equal(doc.page_count, 8);
    assert.equal(doc.failure_category, null);

    // Assert strictly that failure_reason and private fields are NOT present
    const record = doc as unknown as Record<string, unknown>;
    assert.equal(record.failure_reason, undefined);
    assert.equal(record.storage_path, undefined);
    assert.equal(record.storage_disk, undefined);
    assert.equal(record.file_hash, undefined);
    assert.equal(record.meta, undefined);
    assert.equal(record.user_id, undefined);
    assert.equal(record.user, undefined);
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test('ManagementClient: sanitizeItem strictly validates record shapes and rejects malformed records', async () => {
  const originalFetch = globalThis.fetch;

  const malformedItems = [
    { id: -1, source_type: 'upload', original_filename: 'a.pdf', mime_type: 'application/pdf', file_size: 10, status: 'uploaded', page_count: null, failure_category: null, created_at: null, updated_at: null },
    { id: '42', source_type: 'upload', original_filename: 'a.pdf', mime_type: 'application/pdf', file_size: 10, status: 'uploaded', page_count: null, failure_category: null, created_at: null, updated_at: null },
    { id: 1, source_type: '', original_filename: 'a.pdf', mime_type: 'application/pdf', file_size: 10, status: 'uploaded', page_count: null, failure_category: null, created_at: null, updated_at: null },
    { id: 1, source_type: 'upload', original_filename: '   ', mime_type: 'application/pdf', file_size: 10, status: 'uploaded', page_count: null, failure_category: null, created_at: null, updated_at: null },
    { id: 1, source_type: 'upload', original_filename: 'a.pdf', mime_type: '', file_size: 10, status: 'uploaded', page_count: null, failure_category: null, created_at: null, updated_at: null },
    { id: 1, source_type: 'upload', original_filename: 'a.pdf', mime_type: 'application/pdf', file_size: -5, status: 'uploaded', page_count: null, failure_category: null, created_at: null, updated_at: null },
    { id: 1, source_type: 'upload', original_filename: 'a.pdf', mime_type: 'application/pdf', file_size: 10, status: 'unknown_status', page_count: null, failure_category: null, created_at: null, updated_at: null },
    { id: 1, source_type: 'upload', original_filename: 'a.pdf', mime_type: 'application/pdf', file_size: 10, status: 'uploaded', page_count: 0, failure_category: null, created_at: null, updated_at: null },
    { id: 1, source_type: 'upload', original_filename: 'a.pdf', mime_type: 'application/pdf', file_size: 10, status: 'uploaded', page_count: null, failure_category: 'raw_sql_error', created_at: null, updated_at: null },
  ];

  try {
    for (const badItem of malformedItems) {
      globalThis.fetch = (async () => {
        return new Response(
          JSON.stringify({
            meta: { version: '1.0' },
            data: badItem,
          }),
          { status: 200, headers: { 'Content-Type': 'application/json' } }
        );
      }) as typeof fetch;

      const client = new ManagementClient({
        baseUrl: 'http://localhost:8000',
        token: 'test-token',
      });

      await assert.rejects(
        () => client.getDocument(1),
        (err: unknown) => {
          assert(err instanceof ManagementClientError);
          assert.match(err.message, /Invalid document record/);
          return true;
        }
      );
    }
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test('ManagementClient: token redaction redacts all occurrences in error messages', async () => {
  const secretToken = 'ultra-secret-bearer-token-12345';
  const originalFetch = globalThis.fetch;

  try {
    globalThis.fetch = (async () => {
      throw new Error(
        `Failed with token ${secretToken} when retrying with token ${secretToken} and header Bearer ${secretToken}`
      );
    }) as typeof fetch;

    const client = new ManagementClient({
      baseUrl: 'http://localhost:8000',
      token: secretToken,
    });

    await assert.rejects(
      () => client.getDocument(1),
      (err: unknown) => {
        assert(err instanceof ManagementClientError);
        // Ensure no occurrences of the plaintext token exist
        assert.equal(err.message.includes(secretToken), false);
        // Verify multiple redaction markers appear
        const matches = err.message.match(/\[REDACTED\]/g);
        assert(matches && matches.length === 3);
        return true;
      }
    );
  } finally {
    globalThis.fetch = originalFetch;
  }
});
