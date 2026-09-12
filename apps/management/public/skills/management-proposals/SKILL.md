---
name: management-proposals
description: Prepare bounded catalog, FAQ, or knowledge proposals from customer-provided documents through Management MCP/CLI. Use preview and submit only; a human administrator approves and applies every change.
---

# Management Proposals

## Purpose and non-negotiable boundary

Use this skill when an authorized operator has connected Management MCP/CLI and asks to turn facts from a customer document into draft business-data changes.

This is not direct CRUD. The only allowed write path is:

1. Read `agent_schema`.
2. Read bounded existing records with `agent_records_search` / `agent_record_get` when needed.
3. Create `agent_changes_preview`.
4. Explain the proposed diff and unresolved fields.
5. Only after the operator asks, use `agent_changes_submit` with a new idempotency key.
6. Stop. A signed-in human administrator must review, approve, and separately apply the change set in Management.

There is no tool for SQL, database tables, schema changes, approve, apply, publish, delete, file download, OCR, or automatic customer-facing updates. The agent cannot approve, apply, publish, or delete data. Never simulate these actions or claim they happened.

## Credentials and connection

This public skill grants no access. The operator creates an expiring key at `/admin/ai-setup` and puts it into private local client credentials. Never ask for a key in chat, print environment variables, inspect a configuration file containing a key, or put a key in a command/URL/log.

For browser-authorized Remote MCP read `/docs/remote-mcp.md` and check service status at `/admin/ai-setup`. The human logs in and approves scopes; the client handles credentials. Request only required entities and read/proposal scopes. A website root is not an MCP endpoint. Client/account support still needs verification. Local stdio MCP/CLI for Codex, Claude Code, Antigravity and Terminal remains in Advanced.

## Working from a PDF or brochure

Management's `/admin/documents` upload UI is retired. Ask the operator to attach the PDF
to their external AI client, not upload it into Management. Historical document IDs remain
usable as evidence when permitted; use `external_label` for new externally read sources.

- Read the PDF only through a capability the operator already connected. Management's document metadata tools do not expose file contents.
- Extract explicit facts only. Preserve uncertainty. A project brochure is not proof of a live offer, price, stock, promotion, availability, or policy.
- For every field that comes from a private uploaded document, attach a `document_id`, page, and bounded `field_paths`; the document must belong to the same administrator who issued the temporary key. Otherwise use a short `external_label`. An external label is unverified evidence.
- Do not invent missing values. Omit unknown fields. Do not turn common building fees or sample layouts into a unit price or availability.
- Use `group → variant → offer` or `group → offer` only when the source supports that hierarchy. A project and its room types normally become group/variant drafts; an actual offer needs separately confirmed availability.

## Optional structured profiles

Check `agent_schema.catalog_profiles` before writing. When available, use `property_project` on a group for developer/operator/brand, facilities, project counts and dated construction facts; use `property_layout` on a variant for layout type and `area_range`. Names and addresses remain existing core fields. Other businesses can omit profiles. Never invent profile fields or alter the schema through a proposal.

Project facts come from the group; layout facts from its variants; actual price and availability from offers only. A total unit count or brochure description is not proof of current inventory. Keep unknown construction status as `unknown`; a known status requires an evidence date. A document review date does not confirm current construction status. Read visible PDF pages, not hidden extracted text alone.

Use exact catalog search filters `profile`, `record_kind`, `parent_id`, and `facility` when exposed by the connected tool. Facility requires `profile=property_project`; read the allowed vocabulary from the schema. If a local client lacks these fields, update that client or use its existing bounded text search—never pretend a filter was applied.

Attach evidence with field paths such as `profile_data.facilities` and `profile_data.area_range`. Omit missing values or use null; zero is a fact, not a substitute for unknown. On update, omitted `profile_data` is preserved but a supplied object replaces the entire map. Fetch the current record, preserve unrelated keys, and use its exact version. Preview before submitting.

## Proposal rules

- Read `agent_schema` first; it is the source of current entity/field limits.
- Allowed entities depend on the key: `catalog`, `faq`, and/or `knowledge`.
- Allowed actions are `create`, `update`, `archive`, and `restore`. Archive is recoverable; never describe it as hard delete.
- For update/archive/restore, read the current record and use its exact `lock_version` as `expected_version`. If preview/apply reports a conflict, stop and have the operator review the newer data.
- Use a short unique `client_ref` for each create. A catalog child can use `parent_client_ref` only for an earlier create in the same proposal.
- Keep one proposal focused and no more than 50 operations. Preview does not reserve/lock records.
- New records become unpublished/inactive drafts. Do not include `is_published`, `is_active`, storage paths, SQL, or arbitrary keys. Publishing is a separate administrator action.

## Tool workflow

1. `agent_schema` — verify permitted entities and defaults.
2. `agent_records_search` — look up possible duplicates; use bounded search only.
3. `agent_record_get` — get one record and its `lock_version` before editing it.
4. `agent_changes_preview` — validate the exact operations. Fix all errors before submission.
5. `agent_changes_submit` — submit with a new 8–128 character idempotency key. If retrying the exact same payload after an uncertain response, reuse that same key; never reuse it for changed content.
6. `agent_changes_get` — report the result as `proposed`, `approved`, `applied`, `rejected`, `conflicted`, or `suspended`. Do not interpret `approved` as applied.

## What to report to the operator

State separately: explicit source facts; fields intentionally left unknown; unverified external vs. verified private-document evidence; immediate customer impact if an update targets currently published data; and whether the proposal was only previewed, submitted, or actually applied by an administrator.

On 401, 403, 404, 422, 429, conflict, or network failure, report the actual failure. Do not weaken HTTPS, broaden a key, retry in a loop, or fabricate data.
