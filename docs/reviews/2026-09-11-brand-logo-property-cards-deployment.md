# Brand logo and property card deployment

Date: 2026-09-11
Environment: GCP project `aione-zone1`, VM `ai-bot-chatwoot-vm` (`asia-southeast1-b`)
Management: `https://mgmt.aionecloud.space`
Status: deployed

## Scope

- Replaced the Management sidebar and login logo with the supplied transparent Aion3 PNG.
- In dark mode, the logo receives a restrained white glow behind the mark; light mode has no glow.
- Replaced the property-list table with responsive property cards. Each card preserves the existing
  search/filter/pagination and authorised edit/archive actions, and shows the primary image (or a
  missing-image fallback), transaction type, draft state, category, location, room/area facts,
  price, and lifecycle badges.

## Release

- Previous active release: `2026-09-11-brand-logo-r2`.
- Active release: `2026-09-11-property-cards`.
- Only `management-php` was rebuilt and recreated. Management Nginx, MySQL, Chatwoot, AI services,
  Caddy, runtime secrets, and database records were not changed.
- No database migration or seed ran.

## Verification

- Local Management `npm run typecheck`, `npm run lint`, and `npm run build` passed. Vite emitted
  only the existing large-client-bundle warning.
- VM production image build passed and the rebuilt static bundle includes the card UI and new logo.
- VM and external HTTPS checks both returned HTTP 200 for `/up` and `/login` after PHP-FPM startup.
- An authenticated browser check confirmed the cards render at
  `https://mgmt.aionecloud.space/admin/packages` in the active dark theme.

## Rollback

From `/home/macarthur/releases/2026-09-11-brand-logo-r2`, run:

```bash
docker compose -f compose.yml -f compose.override.yml -f compose.card-deploy.yml up -d --no-deps management-php
```

The pre-card PHP image is also retained as
`ai-bot-chatwoot-management-php:before-property-cards-20260911`.
