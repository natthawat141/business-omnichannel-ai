---
version: alpha
name: Aion3 Management
description: Thai-first administration with readable operational guides and explicit review actions.
colors:
  primary: "#18181b"
  text: "#202124"
  muted: "#626771"
  border: "#e5e7eb"
  surface: "#ffffff"
  soft: "#f7f8fa"
  guidance: "#216344"
typography:
  sans:
    fontFamily: "Noto Sans Thai, Instrument Sans, ui-sans-serif, system-ui, sans-serif"
  mono:
    fontFamily: "ui-monospace, monospace"
rounded:
  control: "0.375rem"
  panel: "0.75rem"
spacing:
  guide-panel: "1.75rem"
  guide-gap: "1.5rem"
---

# Aion3 Management UI

## Product context and visual direction

The reference is the existing Management workspace: a persistent navigation rail, black primary
actions, quiet white surfaces and Thai business copy. Administrators connect AI clients, inspect
business data and review proposals. This is a product interface; do not introduce marketing
heroes, decorative labels above headings, unrelated font pairings or new brand colors.

The AI setup guide uses one document with direct section links. Browser connection comes first;
local setup remains visible in the same flow. Client selection changes instructions in place.
The page has one whole-guide copy action and separate copies for useful commands.

Thai is the UI language; client names, protocol terms and executable commands retain their
original spelling. This is not evidence of a Japan-market requirement. Desktop and narrow phone
views must retain readable instructions and reachable controls.

## Runtime ownership

This document records the existing identity; runtime CSS remains canonical.

| Capability | Maintained owner | Behavior |
| --- | --- | --- |
| Shell and theme | `apps/management/resources/js/components/AdminLayout.tsx` | Existing navigation and `data-theme` light/dark switch |
| Fonts and shared dark controls | `apps/management/resources/css/app.css` | Noto Sans Thai with Latin/system fallbacks |
| Buttons and selects | `apps/management/resources/js/components/ui.tsx` | Shared Button and native SelectInput; platform-owned select popup is intentional |
| Copyable instructions | `apps/management/resources/js/components/TerminalBlock.tsx` | Accessible code/prompt panels; all setup copies use the page's copy callback |
| Guide colors and layout | `apps/management/resources/css/ai-setup.css` | `--ai-*` tokens and `.ai-unified`; 1120px maximum, single-column local instructions |
| Guide/export content | `apps/management/resources/js/lib/aiSetupGuide.ts` | Explicit Markdown generation; no DOM/input scraping or issued credential parameter |
| Feedback and recovery | Unified AI Setup | One inline live status region; selectable safe export after clipboard denial |
| Revoke confirmation | Existing inline confirmation pattern, unified AI Setup | Name the connection/key, explain consequence, support cancel and bounded pending state |

## Observable behavior

- Start with the useful heading. Do not add decorative eyebrows, kickers or status-like slogans.
- Keep page scrolling natural. Bound long prompt/code panels to 400px with an operable scrollbar;
  respect platform/forced-colors scrollbar behavior. No fixed-height workspace that clips forms.
- Use section gaps and borders for hierarchy. Avoid repeating cards inside cards.
- Headers wrap, controls remain reachable, long URLs/commands wrap, and client buttons wrap on phones.
- The existing dark theme maps the same semantic tokens; it does not depend on OS color preference.
- Copy feedback must not change the copy button's geometry. No credential appears in status text.
- Sensitive issued values are masked initially, with an accessible show/hide control, and remain
  in memory only. The whole-page export is built from safe metadata, never from these values.
- Proposal permissions are explicit. The UI never equates a copied prompt or saved connection
  with a verified successful tool call. Submission, human approval and applying remain distinct.
- Existing admin authentication, server-side scope checks, CSRF and revocation contracts in
  `SPEC.md` and `docs/operations` take precedence over presentation defaults.

## Verification

`apps/management/tests/Browser/ai-setup.mjs` serves the actual compiled UI with synthetic props
and request fixtures. It checks copying, secret exclusion, client/scope switching, failure recovery,
responsive layout, dark mode and the old Advanced URL. This does not establish real-client OAuth
compatibility. PHP feature tests own the authentication, scope and MCP protocol contracts.
