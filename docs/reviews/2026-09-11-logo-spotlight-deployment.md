# Logo spotlight deployment

Date: 2026-09-11
Environment: GCP project `aione-zone1`, VM `ai-bot-chatwoot-vm` (`asia-southeast1-b`)
Management: `https://mgmt.aionecloud.space`
Status: deployed

## Scope

Dark mode now renders a large, soft white radial spotlight behind the Aion3 logo only. The
Property Management text remains outside that light effect. The logo itself retains a stronger
white drop shadow so the mark remains legible at the centre of the spotlight.

## Release and verification

- Previous release: `2026-09-11-property-cards`.
- Active release: `2026-09-11-logo-spotlight`.
- Only Management PHP was rebuilt and recreated; no database migration, seed, credential, or
  other service change occurred.
- Local TypeScript check, lint, and Vite production build passed; only the existing Vite bundle-size
  warning was reported.
- The VM production image build passed. External `/up` and `/login` checks returned HTTP 200.
- An authenticated production-browser check confirmed the spotlight visually in dark mode.

## Rollback

From `/home/macarthur/releases/2026-09-11-property-cards`, run:

```bash
docker compose -f compose.yml -f compose.override.yml -f compose.card-deploy.yml up -d --no-deps management-php
```

The prior image is retained as
`ai-bot-chatwoot-management-php:before-logo-spotlight-20260911`.
