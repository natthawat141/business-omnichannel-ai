# Remove manual image-link input deployment

Date: 2026-09-11
Environment: GCP VM `aione-zone1`, Management at `https://mgmt.aionecloud.space`
Status: deployed

## Scope

Removed the manual primary-image URL control from the property form. Operators now select a
local image file only. Existing stored image URLs and import/API compatibility were not changed.

## Release

- Previous active release: `2026-09-11-remote-mcp-oauth`
- Active release: `2026-09-11-remove-image-link`
- Previous Management image tag: `ai-bot-chatwoot-management-php:before-remove-image-link-20260911`
- Only runtime source transferred: `apps/management/resources/js/pages/Packages/Form.tsx`
- No database migration, seed, credential issuance, Caddy change, Chatwoot change, or local Docker use.

## Verification

- VM frontend typecheck, lint and Vite build passed after syncing the current catalog type definition
  into the existing VM review workspace. The build reports the existing large-JS-bundle warning only.
- Docker production image build passed and `management-php` was recreated from the new image.
- The built production assets contain `เลือกรูปจากเครื่อง` and no longer contain Google Drive-share,
  manual-image-link or HTTPS-image-link UI strings.
- `https://mgmt.aionecloud.space/login` returned HTTP 200 after PHP-FPM boot.
- `https://mgmt.aionecloud.space/up` returned HTTP 200.

## Notes

- An immediate post-recreate login request briefly returned 502 while PHP-FPM booted; it recovered to
  200 after configuration/route/view cache warmup.
- Local PHP feature tests remain blocked by the existing missing `Laravel\\Passport` package in the
  current local checkout. This deployment does not change Passport or authentication dependencies.
- Local typecheck also has pre-existing missing Base UI modules. VM review checks passed with its
  installed dependencies and current type definition.
- Rollback: from `/home/macarthur/releases/2026-09-11-remote-mcp-oauth`, run
  `docker compose up -d --no-deps management-php` and reload Management nginx. The prior image tag
  above is retained as an additional rollback point.
