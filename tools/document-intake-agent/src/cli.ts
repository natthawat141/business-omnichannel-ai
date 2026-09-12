import { ManagementClient, ManagementClientError } from './client.js';
import { readFileSync, statSync } from 'node:fs';
import { AGENT_ENTITIES, type AgentEntity, type ProposalOperation } from './types.js';

function printHelp(): void {
  console.log(`Management Agent CLI

Usage:
  document-intake list [--status STATUS] [--limit N] [--page N]
  document-intake get <id>
  document-intake schema
  document-intake records <catalog|faq|knowledge> [--query TEXT] [--limit N] [--include-archived]
    Catalog filters: [--profile NAME] [--record-kind group|variant|offer] [--parent-id N] [--facility NAME]
  document-intake record <catalog|faq|knowledge> <id>
  document-intake changes-preview <proposal.json>
  document-intake changes-submit <proposal.json> --idempotency-key KEY
  document-intake changes-get <uuid>

Commands:
  list    List document intake records with optional filtering and pagination
  get     Inspect safe metadata for a specific document by its integer ID
  schema  Read the scoped proposal schema (never SQL or database credentials)
  records Search bounded allowlisted proposal records
  record  Inspect one proposal record including its lock_version
  changes-preview  Validate a local proposal JSON without changing data
  changes-submit   Submit an immutable proposal for human review only
  changes-get      Read a proposal status created with the same key

Options:
  --status  Filter by document status (uploaded, extracting, ready, ocr_required, failed)
  --limit   Maximum number of records to return (1-50, default 15)
  --page    Page number (1-1000, default 1)
  --help    Show this help message

Environment Variables:
  MANAGEMENT_API_BASE_URL   Management API base URL (must use HTTPS except for localhost)
  MANAGEMENT_API_TOKEN      Bearer token with the selected scoped ability
`);
}

function parseEntity(value: string | undefined): AgentEntity {
  if (!value || !AGENT_ENTITIES.includes(value as AgentEntity)) {
    throw new ManagementClientError('Entity must be catalog, faq, or knowledge.');
  }
  return value as AgentEntity;
}

function readProposal(path: string | undefined): ProposalOperation[] {
  if (!path || !path.endsWith('.json')) throw new ManagementClientError('Proposal input must be a .json file.');
  const size = statSync(path).size;
  if (size < 1 || size > 1_048_576) throw new ManagementClientError('Proposal JSON must be between 1 byte and 1 MiB.');
  let parsed: unknown;
  try { parsed = JSON.parse(readFileSync(path, 'utf8')); } catch { throw new ManagementClientError('Proposal file must contain valid JSON.'); }
  if (!parsed || typeof parsed !== 'object' || !Array.isArray((parsed as { operations?: unknown }).operations)) {
    throw new ManagementClientError('Proposal JSON must contain an operations array.');
  }
  return (parsed as { operations: ProposalOperation[] }).operations;
}

export async function runCli(args: string[]): Promise<number> {
  const command = args[0];

  if (!command || command === '--help' || command === '-h' || command === 'help') {
    printHelp();
    return 0;
  }

  try {
    const client = new ManagementClient();

    if (command === 'list') {
      let status: string | undefined;
      let limit: number | undefined;
      let page: number | undefined;

      for (let i = 1; i < args.length; i++) {
        const arg = args[i];
        if (arg === '--status') {
          i++;
          if (i >= args.length) {
            throw new ManagementClientError('--status requires a value.');
          }
          status = args[i];
        } else if (arg === '--limit') {
          i++;
          if (i >= args.length) {
            throw new ManagementClientError('--limit requires an integer value.');
          }
          limit = Number(args[i]);
          if (!Number.isInteger(limit) || limit < 1 || limit > 50) {
            throw new ManagementClientError('--limit must be an integer between 1 and 50.');
          }
        } else if (arg === '--page') {
          i++;
          if (i >= args.length) {
            throw new ManagementClientError('--page requires an integer value.');
          }
          page = Number(args[i]);
          if (!Number.isInteger(page) || page < 1 || page > 1000) {
            throw new ManagementClientError('--page must be an integer between 1 and 1000.');
          }
        } else {
          throw new ManagementClientError(`Unknown option '${arg}'. Run 'document-intake --help' for usage.`);
        }
      }

      const result = await client.listDocuments({ status, limit, page });
      console.log(JSON.stringify(result, null, 2));
      return 0;
    }

    if (command === 'get') {
      const idArg = args[1];
      if (!idArg) {
        throw new ManagementClientError("Missing required document ID. Usage: document-intake get <id>");
      }
      if (args.length > 2) {
        throw new ManagementClientError(`Unexpected argument '${args[2]}'. Usage: document-intake get <id>`);
      }

      const result = await client.getDocument(idArg);
      console.log(JSON.stringify(result, null, 2));
      return 0;
    }

    if (command === 'schema') {
      if (args.length !== 1) throw new ManagementClientError('Usage: document-intake schema');
      console.log(JSON.stringify(await client.getAgentSchema(), null, 2));
      return 0;
    }

    if (command === 'records') {
      const entity = parseEntity(args[1]);
      let query: string | undefined;
      let limit: number | undefined;
      let includeArchived = false;
      const catalog: { profile?: string; recordKind?: string; parentId?: number; facility?: string } = {};
      for (let i = 2; i < args.length; i++) {
        if (args[i] === '--query') { query = args[++i]; if (query === undefined) throw new ManagementClientError('--query requires text.'); }
        else if (args[i] === '--limit') { limit = Number(args[++i]); if (!Number.isInteger(limit) || limit < 1 || limit > 50) throw new ManagementClientError('--limit must be an integer between 1 and 50.'); }
        else if (args[i] === '--include-archived') includeArchived = true;
        else if (['--profile', '--record-kind', '--facility'].includes(args[i])) {
          const flag = args[i]; const value = args[++i];
          if (!value || value.startsWith('--')) throw new ManagementClientError(`${flag} requires a value.`);
          if (flag === '--profile') catalog.profile = value;
          else if (flag === '--record-kind') catalog.recordKind = value;
          else catalog.facility = value;
        }
        else if (args[i] === '--parent-id') catalog.parentId = Number(args[++i]);
        else throw new ManagementClientError(`Unknown option '${args[i]}'.`);
      }
      console.log(JSON.stringify(await client.listAgentRecords(entity, { query, limit, includeArchived, ...catalog }), null, 2));
      return 0;
    }

    if (command === 'record') {
      const entity = parseEntity(args[1]);
      if (!args[2] || args.length !== 3) throw new ManagementClientError('Usage: document-intake record <entity> <id>');
      console.log(JSON.stringify(await client.getAgentRecord(entity, args[2]), null, 2));
      return 0;
    }

    if (command === 'changes-preview') {
      if (args.length !== 2) throw new ManagementClientError('Usage: document-intake changes-preview <proposal.json>');
      console.log(JSON.stringify(await client.previewChanges(readProposal(args[1])), null, 2));
      return 0;
    }

    if (command === 'changes-submit') {
      if (!args[1] || args[2] !== '--idempotency-key' || !args[3] || args.length !== 4) {
        throw new ManagementClientError('Usage: document-intake changes-submit <proposal.json> --idempotency-key KEY');
      }
      console.log(JSON.stringify(await client.submitChanges(readProposal(args[1]), args[3]), null, 2));
      return 0;
    }

    if (command === 'changes-get') {
      if (!args[1] || args.length !== 2) throw new ManagementClientError('Usage: document-intake changes-get <uuid>');
      console.log(JSON.stringify(await client.getChangeSet(args[1]), null, 2));
      return 0;
    }

    throw new ManagementClientError(`Unknown command '${command}'. Run 'document-intake --help' for usage.`);
  } catch (err: unknown) {
    if (err instanceof ManagementClientError) {
      console.error(`Error: ${err.message}`);
    } else if (err instanceof Error) {
      console.error(`Unexpected error: ${err.message}`);
    } else {
      console.error('An unexpected error occurred.');
    }
    return 1;
  }
}

// Execute directly if run as main
if (import.meta.url === `file://${process.argv[1]}`) {
  runCli(process.argv.slice(2)).then((code) => {
    if (code !== 0) {
      process.exit(code);
    }
  });
}
