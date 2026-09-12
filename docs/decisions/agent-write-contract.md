# Agent write contract — approved direction, staged implementation

Approved: 2026-09-06. Canonical requirements: SPEC FR-AGENT-001–008.

Management owns validation and persistence; external staff agents interpret source documents.
The customer-facing Chatwoot agent retains read-only business access. No SQL tools, new queues,
vector database, OCR service or multi-tenancy are introduced.

## Slice 1A: typed attributes, before writable MCP

- Add `package_categories.schema_version` default 1. Version 1 is the unchanged legacy shape,
  including existing core-field search descriptors. A first explicit typed definition save upgrades
  to version 2; later definition edits increment the version. Names/labels without definition edits
  do not increment it. The server, never request mass assignment, chooses the version.
- Typed definitions use a list of key/label/type/unit/nullable/allowed_values/searchable/
  allowed_operators objects. Keys cannot shadow any package core field.
- All attributes are optional: absent means no assertion, nullable controls explicit null only.
  Do not encode unknown as zero, false or empty text. Unknown reasons and per-field evidence belong
  to the subsequent proposal contract; this slice does not pretend to implement them.
- Existing version-1 string attributes keep their old validation. Do not automatically infer types
  from strings or rewrite existing JSON. Opting a category with populated legacy attributes into
  typed mode is refused pending explicit conversion review.
- Once a typed category has records, definition deletion/type/unit/constraints changes are refused;
  optional additions are allowed. This conservative policy avoids invalidating stored data.
- Import adds an optional final `attributes` JSON column. Both existing 9-column and 23-column files
  remain accepted. Values keep their JSON types; no string-to-number or unit conversion.
- No new public search operators yet. `searchable` is schema metadata, not a claim that a dynamic
  query implementation is already deployed.

## Subsequent slices

1B: record kinds, parent relationships, shared versioned writers, archive and visibility guarantees.
2–4: proposal API/engine, evidence/history, versioned MCP, opt-in keys and admin batch review.
5: FAQ/knowledge adapters. 6: isolated MySQL and per-client pilot, then separate rollout approval.

Brochures can describe project groups and room types without an actual offer. Maintenance fees
and reserve funds are not sale prices. No inventory may be created from that inference.
