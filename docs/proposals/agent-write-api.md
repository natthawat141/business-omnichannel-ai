# Staff agent API — implemented development contract

Governed by SPEC FR-AGENT-001–008 and the approved 2026-09-06 plan. This contract is implemented
in the development slice and remains un-deployed: production migration/deployment and real-client
pilots still require a separate product-owner approval.

| Capability | Proposed interface | Authority |
|---|---|---|
| Schema discovery | `GET /schema`, MCP `agent_schema` | agent:read |
| Scoped admin records | `GET /records/{entity}`, MCP `agent_records_search` / `agent_record_get` | agent:read plus entity allowlist |
| Validate batch | `POST /changes/preview`, MCP `agent_changes_preview` | changes:write; no live writes |
| Store immutable proposal | `POST /changes`, MCP `agent_changes_submit` | changes:write plus entity/action allowlists |
| Poll batch | `GET /changes/{uuid}`, MCP `agent_changes_get` | scoped read; no mutation |
| Propose schema additions | not implemented | schema remains admin-owned |
| Apply / publish | authenticated admin review, CSRF | no bearer-token self-approval |

The HTTP namespace will be `/api/v1/agent`, separate from customer-facing read APIs. Each batch
has <=50 operations and a <=1 MiB JSON body. The server hashes canonical validated payloads and
binds approval to that immutable hash and schema versions. An idempotency key reused with a
different payload returns 409; retries of the same payload return the existing proposal.

Updates/archive/restore require target ID and expected version. Creates use local `client_ref`
references for parent/child creation within one transaction. Patch changes only named fields,
replaces ranges atomically, and distinguishes explicit null from missing data. All business rows
are unchanged until admin apply. Apply revalidates inside locks and commits all operations or none.

After reviewing a visible diff and sources for each proposal, an authenticated administrator may
select up to 25 **already-approved** change sets and confirm one bulk apply action. The server
locks and revalidates every selected set in one transaction. If any selected set conflicts, every
business-data write from that bulk action is rolled back; the failing set is marked conflicted for
review, while the other selected sets remain approved. This convenience action never approves a
proposal, never publishes a record, and is unavailable to bearer-token or MCP clients.

New records apply as drafts. Agents cannot send publication fields or fake approval flags to
bypass the gate. An update of an already published row requires disclosure that customer-facing
data changes immediately. Archive is recoverable, rejects parents with live children, and removes
records from public retrieval. Restore/revert still validate current versions and unique identity.

Evidence can reference a private uploaded DocumentSource/page owned by the same key issuer or an unverified external attachment.
No source URL fetch, public PDF download or synthetic claim of server-verified provenance. Review
must distinguish a brochure's project/room-type facts from actual available offers and price.

An expired source key stops new API requests but does not invalidate an already accepted proposal;
admin apply uses independent current authority. Security revocation suspends pending proposals.
No existing key receives new permissions automatically; customer-facing Chatwoot keys stay read-only.
