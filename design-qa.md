# Design QA — AI setup developer documentation refresh

Date: 2026-09-06
Scope: `apps/management/resources/js/pages/AiSetup.tsx`

## Reference and intended result

- Reference: the product-owner screenshots supplied in this task, especially the developer-docs
  layout with an action area, segmented setup mode, terminal panel and section navigation.
- Implemented: a light-shell documentation treatment that matches the Management admin visual
  language, rather than changing the whole product to a dark documentation site.
- Components: Base UI Tabs/Tooltip plus the reusable `TerminalBlock` component; existing Lucide
  icons remain the visual language for client and terminal actions.

## Automated checks

- PASS — TypeScript check, ESLint and Vite production build on GCP `aione-zone1` development VM
  `dev-container-1`.
- PASS — targeted Laravel tests: 10 tests, 81 assertions on PHP 8.4.25.
- No macOS Docker, production service, production VM, deployment or production data is used for
  this QA.

## Visual comparison

2026-09-11 follow-up: **desktop browser verification passed** for the connected documentation
refresh. The actual VM-built app was rendered with synthetic Inertia props (empty tokens,
preview@example.test) through an SSH-only dev preview. Verified English prompt headings,
Preview/Markdown switching, Copy prompt feedback, and the visible Documentation link.
The public Blade docs were rendered through Laravel; Copy Page returned Markdown successfully.
No real credentials were issued. Mobile and real client integration remain unverified.

The change is not deployed to the production Management domain. The isolated preview is
temporary and uses synthetic page props rather than an authenticated customer session.
