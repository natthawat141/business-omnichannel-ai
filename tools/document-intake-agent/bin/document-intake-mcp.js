#!/usr/bin/env node
import { runMcpServer } from '../dist/mcp.js';

runMcpServer().catch((err) => {
  console.error(
    'Fatal MCP server error:',
    err instanceof Error ? err.message : 'Unknown error'
  );
  process.exit(1);
});
