# Typed catalog — slice 1A

Development implementation, not deployed. SPEC FR-AGENT-001/002/007 (partial); FR-CAT-003/004.

## Storage and compatibility

Existing `packages.attributes` and `package_categories.attribute_definitions` stay JSON columns.
Only `package_categories.schema_version` is added, with default 1. Migration does not rewrite
category definitions or package data. Version 1 preserves legacy string/null attributes and the
old seeder's fixed-column descriptors. No database, table or core field is renamed.

An explicit admin-session category create/update with `attribute_definitions` activates typed
mode (version 2); later definition changes increment the server-owned version. Editors may still
edit category names but cannot submit definitions. Bearer tokens do not gain write access.
The current category UI has no schema editor yet: friendly schema proposal/review UX remains pending.

```json
{
  "attribute_definitions": [
    {
      "key": "area_range",
      "label": "พื้นที่ใช้สอยตามประเภทห้อง",
      "type": "numeric_range",
      "unit": "sqm",
      "nullable": true,
      "allowed_values": [],
      "searchable": false,
      "allowed_operators": []
    }
  ]
}
```

Max 40 definitions/typed values; keys use `[a-z][a-z0-9_]{0,59}` and cannot duplicate a package
core column (including price, availability and future relationship/version fields). Supported types:

| Type | JSON value and bounds |
|---|---|
| string | String, max 500 characters |
| integer | JSON integer, absolute value <= 10^12; numeric strings rejected |
| decimal | Finite JSON number, absolute value <= 10^12; numeric strings rejected |
| boolean | JSON true/false, not strings or integers |
| enum | One of 1–50 distinct registered strings (max 100 characters each) |
| string_list | List of up to 50 nonblank strings (max 100 characters each) |
| numeric_range | Exactly min/max/unit; finite numbers within bounds, min <= max, exact schema unit |

Every attribute is optional. Explicit field `null` requires `nullable=true`. An omitted `attributes`
field on an existing admin update preserves the existing map; an explicitly supplied map replaces it,
and `attributes:null` clears the map. This is the **existing admin form contract**, not the future
agent PATCH contract. No deep merge, automatic unit conversion or inferred values.

`searchable`/`allowed_operators` describe a reviewed schema but do not activate public filters yet.
Existing Catalog Search API still accepts only its fixed allowlist. Unknown attributes return
field-path validation errors and direct callers to request schema review; no automatic schema proposal
endpoint is delivered in 1A.

## Save paths and schema changes

- Shared `AttributeValidator` runs on relevant Eloquent package saves, admin forms and import paths.
- Admin forms decode JSON strings using request input explicitly (not Symfony's attributes property).
- Preview and confirm validate typed values; export includes an `attributes` JSON column. Existing
  9/23-column imports remain supported and creates stay unpublished; duplicate codes are skipped.
- Category activation with populated legacy attributes is refused for explicit conversion review.
- Typed categories with records allow optional additions only. Removing/modifying existing definitions
  or deleting the category is refused. Empty categories can be redefined.
- Schema writers and package saves lock category rows in transactions; stale model schema writes
  return 409. SQL bulk updates bypass model events and are not approved catalog writer paths.
- This is **not** catalog-wide optimistic locking: stale browser forms/record updates, archive/restore
  and changesets still require the next slice. No concurrency guarantee is claimed from sequential tests.
- Import failure logs contain exception class only, not raw messages, row contents or filenames.

## Rollout gate

Do not migrate production as part of this slice. First resolve the MySQL full-suite findings,
complete the catalog writer/visibility slice, back up, demonstrate restore and approve rollout.
Migration down intentionally refuses to drop the schema discriminator. Rollback must retain columns
and all data. Once typed records exist, the previous string-only editor is not a complete write
rollback: stop typed writes and review a forward-compatible fix instead of rewriting JSON as strings.

MCP proposal tools, keys with new abilities, record_kind/parents, archive, evidence, unknown reasons,
record revisions, admin batch review, FAQ/knowledge writes and client pilots are **not implemented** here.
