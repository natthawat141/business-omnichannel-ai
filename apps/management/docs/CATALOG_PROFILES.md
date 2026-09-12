# Optional catalog profiles

Development slice: SPEC FR-PROFILE-001–004, approved 2026-09-11. No production migration or sample insertion is part of this delivery.

## Data ownership

`packages.profile` (nullable string) selects a server-owned versioned schema; `profile_data` (nullable JSON object) stores its values. Categories and their existing `attributes` remain independent. No real-estate columns are added. Other businesses continue to use the unprofiled catalog; new industries can add reviewed profiles to `CatalogProfiles::schemas()`.

| Record | Profile | Facts |
|---|---|---|
| group | property_project | Developer, operator/brand, facilities, project counts, construction status and evidence date |
| variant, child of group | property_layout | Room-layout type and bounded area range |
| offer, child of group or variant | none | Actual unit/listing price, floor, area, transaction and availability |

Use existing `name_th/name_en` for names and existing address/location fields for location. A brochure's total unit count is not available stock. The project profile rejects price/sale_price and available/reserved/sold/rented statuses. Construction status other than unknown requires `construction_status_as_of`; its accuracy still requires human evidence review. All profile fields are optional, with null representing unknown. Empty facilities means an empty reported list, not verified absence of every facility.

## Schema and writes

Read `GET /api/v1/agent/schema` (`agent_schema`) first. `catalog_profiles` includes profile versions, record kinds, field types, bounds and facility enums. `catalog_profile_rules` describes update semantics and source field paths. Only catalog-scoped keys receive catalog profiles.

There are at most 40 profile fields. Counts are JSON integers, coordinates finite bounded numbers, dates valid YYYY-MM-DD, expected completion YYYY-MM. A range is exactly `{ "min": 25.5, "max": 27, "unit": "sqm" }`. Unknown fields, unsupported enums, duplicate facilities, reversed ranges and numeric strings are rejected. Profile rules run on model, admin and proposal writes.

For updates, omit `profile_data` to preserve it; supply an object to **replace the whole map**, not deep merge it. Read the existing record, merge intended changes client-side, and submit with `expected_version`. Null clears the map. Removing a profile requires also clearing its data; changing the record kind alone cannot bypass profile validation. Schema versions are captured under `profile:<name>` in the existing proposal snapshot and checked again at apply.

Admin package forms render schema-backed fields, facility checkboxes and ranges. Changing profiles does not silently delete the previous map; the explicit clear control resets the unsaved form after confirmation. Existing price/parent fields must be made compatible with the selected profile before saving.

## Staff search and MCP/CLI

`GET /api/v1/agent/records/catalog` supports the existing bounded query/limit/archive options plus exact filters:

| Parameter | Constraint |
|---|---|
| profile | Registered profile name |
| record_kind | group, variant, offer |
| parent_id | Positive catalog ID |
| facility | Registered facility; requires profile=property_project |

These filters cannot be used on FAQ/knowledge. They never loosen silently. Both Remote MCP and the updated source version of local stdio MCP expose them on `agent_records_search`. Rebuild/reinstall a local client to use the new CLI flags; existing packaged downloads are not changed by this slice.

```sh
document-intake schema
document-intake records catalog --profile property_project --facility pool --limit 10
document-intake records catalog --parent-id 123 --record-kind variant
```

The parent ID above is illustrative, not a deployed record. These are staff read APIs, not public customer retrieval. The customer-facing catalog search still returns eligible offers only. Connecting project/facility facts to the conversation orchestrator is a separate slice; do not claim the customer bot already answers those queries.

## COCO PARC review fixture

[Copyable JSON example](../tests/Fixtures/coco-parc-proposal.json) contains seven create operations: a project plus Studio, 1BR, 1BR Plus, 2BR, 3BR and Penthouse layouts. It is test/review input, not an automatic seeder or live inventory.

Source: customer-provided COCO-PARC-BROCHURE.pdf, visual pages 4 and 13. Page 13 supports 1 building, 37 storeys, 444 units, 269 parking spaces, 3 passenger lifts and 1 service lift. The six area ranges are 25.5–27, 34.5–48, 49.5, 64.5–66.5, 101.5–113.5 and 134–234 sqm. Page 4 supports developer/operator branding. Facilities are mapped to the registered vocabulary, not copied into free-form search columns.

The extracted text layer contains an expected completion date not visible in the rendered factsheet. It is not used: expected completion, current construction status, coordinates, prices and actual stock remain unknown. `document_reviewed_on` is the date of this document review, not a construction-status confirmation. The brochure's services remain subject to its stated rules and availability.

Sources carry page and `field_paths`; `external_label` references remain unverified. For a real uploaded private document, replace them with a document ID owned by the connected administrator. Never infer source verification from a fixture or successful validation.

Workflow: read schema → search duplicates → preview JSON → explain unknowns → submit on instruction → human review/approve/apply. Apply creates inactive unpublished drafts. No automatic production insert, publish, hard delete or root permission is introduced.

## Migration and verification

`2026_09_11_000002_add_catalog_profiles.php` adds the two nullable fields and profile index without rewriting existing data. Its down migration refuses to drop stored profile data. Production rollout needs a backup and explicit deployment authorization; rollback should retain the columns and use compatible writers.

`CatalogProfileTest` covers schema, validation, exact/empty searches, admin preservation, proposal effective-record checks, review detail payload/evidence, and the full seven-record draft flow. Tests use synthetic keys and an isolated database; they do not prove production MySQL locking or live client authentication.

Existing spreadsheet import/export does not round-trip these new profile fields. Use the profile-aware admin or proposal API. See [delivery review](../../../docs/reviews/2026-09-11-catalog-profiles.md) for commands, results and remaining gates.
