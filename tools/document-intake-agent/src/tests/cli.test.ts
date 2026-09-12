import test from 'node:test';
import assert from 'node:assert/strict';
import { runCli } from '../cli.js';

test('CLI: catalog profile filters are forwarded and require values', async () => {
  const original = globalThis.fetch;
  process.env.MANAGEMENT_API_BASE_URL = 'https://example.com';
  process.env.MANAGEMENT_API_TOKEN = 'synthetic-test-key';
  try {
    globalThis.fetch = (async (input: string | URL | Request) => {
      const url = new URL(String(input));
      assert.equal(url.searchParams.get('profile'), 'property_project');
      assert.equal(url.searchParams.get('facility'), 'pool');
      assert.equal(url.searchParams.get('parent_id'), '12');
      return new Response(JSON.stringify({ data: { entity: 'catalog', items: [], limit: 10 } }));
    }) as typeof fetch;
    assert.equal(await runCli(['records', 'catalog', '--profile', 'property_project', '--facility', 'pool', '--parent-id', '12']), 0);
    assert.equal(await runCli(['records', 'catalog', '--profile']), 1);
    assert.equal(await runCli(['records', 'catalog', '--parent-id', '0']), 1);
  } finally { globalThis.fetch = original; }
});

test('CLI: --help returns 0 without requiring env vars', async () => {
  const code = await runCli(['--help']);
  assert.equal(code, 0);

  const codeEmpty = await runCli([]);
  assert.equal(codeEmpty, 0);
});

test('CLI: rejects unknown command', async () => {
  const code = await runCli(['publish']);
  assert.equal(code, 1);
});

test('CLI: list rejects unknown option and invalid arguments including page bounds', async () => {
  process.env.MANAGEMENT_API_BASE_URL = 'http://localhost:8000';
  process.env.MANAGEMENT_API_TOKEN = 'test-token';

  assert.equal(await runCli(['list', '--unknown']), 1);
  assert.equal(await runCli(['list', '--status']), 1);
  assert.equal(await runCli(['list', '--limit']), 1);
  assert.equal(await runCli(['list', '--limit', '999']), 1);
  assert.equal(await runCli(['list', '--page']), 1);
  assert.equal(await runCli(['list', '--page', '0']), 1);
  assert.equal(await runCli(['list', '--page', '1001']), 1);
});

test('CLI: get rejects missing ID, extra arguments, and non-integer ID', async () => {
  process.env.MANAGEMENT_API_BASE_URL = 'http://localhost:8000';
  process.env.MANAGEMENT_API_TOKEN = 'test-token';

  assert.equal(await runCli(['get']), 1);
  assert.equal(await runCli(['get', '1', 'extra']), 1);
  assert.equal(await runCli(['get', 'not-a-number']), 1);
  assert.equal(await runCli(['get', '-5']), 1);
});

test('CLI: successful list and get with mocked fetch', async () => {
  const originalFetch = globalThis.fetch;

  try {
    globalThis.fetch = (async (input: string | URL | Request) => {
      const urlStr = String(input);
      if (urlStr.includes('/api/v1/documents/10')) {
        return new Response(
          JSON.stringify({
            meta: { version: '1.0' },
            data: {
              id: 10,
              source_type: 'upload',
              original_filename: 'cli_test.pdf',
              mime_type: 'application/pdf',
              file_size: 1024,
              status: 'uploaded',
              page_count: null,
              failure_category: null,
              created_at: '2026-09-04T12:00:00Z',
              updated_at: '2026-09-04T12:00:00Z',
            },
          }),
          { status: 200, headers: { 'Content-Type': 'application/json' } }
        );
      }
      return new Response(
        JSON.stringify({
          meta: {
            version: '1.0',
            count: 1,
            total: 1,
            current_page: 1,
            last_page: 1,
            per_page: 15,
            applied_filters: {},
          },
          data: [],
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } }
      );
    }) as typeof fetch;

    process.env.MANAGEMENT_API_BASE_URL = 'http://localhost:8000';
    process.env.MANAGEMENT_API_TOKEN = 'test-token';

    const listCode = await runCli(['list', '--status', 'uploaded', '--limit', '5', '--page', '1000']);
    assert.equal(listCode, 0);

    const getCode = await runCli(['get', '10']);
    assert.equal(getCode, 0);
  } finally {
    globalThis.fetch = originalFetch;
  }
});
