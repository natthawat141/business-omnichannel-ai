# Agent onboarding review

Requirement: FR-SETUP-006, approved by the product owner with an Agent setup reference.

## Scope

- Client selector above setup content, Agent/Manual mode switch, visible full prompt with Copy prompt.
- Three real steps: prepare client, supply short-lived key privately, verify a real response.
- Public install guide and downloadable compiled CLI/MCP package. Installation no longer requires finding the application repository.
- Key issuance, lifetime, revocation, scopes and API behavior unchanged. No OAuth/remote transport, migrations or new credentials.

## Files

- apps/management/resources/js/pages/AiSetup.tsx
- apps/management/resources/css/ai-setup.css
- apps/management/public/docs/agent-setup.md and public/llms.txt
- tools/document-intake-agent/package.json (package file allowlist)
- apps/management/tests/Feature/AiSkillDocumentationTest.php
- SPEC.md and apps/management/docs/AI_SETUP.md

## Evidence

- Explicit backend tests: 28 passed, 160 assertions. Includes existing token expiry, scope and revocation tests plus prompt/document checks.
- Frontend and tool-package build/test evidence, archive checks and production smoke checks are recorded in the VM deployment record.
- VM frontend typecheck/lint/build passed; 19 tool-package tests passed. Clean archive installation and CLI help worked without credentials. Public guide/package/health return 200, unauthenticated API returns 401. Authenticated production UI verified Agent/Manual switching, Copy prompt feedback, selected-client commands and unavailable cloud state. No phone-size or dark-mode visual QA was performed in this slice.
- No live key entry or third-party client connection is claimed by these tests. Browser copy confirmation is not verification of a working MCP connection.

## Review focus

Check per-client commands and selected label match; changing modes does not lose an in-memory issued key; cloud choices do not offer fake installation; prompt contains no real token; package archive excludes secrets and tests; preserve custom-domain overrides on deployment. Consumer machines require Node/npm and the AI client. The operator launches the client from the same terminal as the hidden key prompt.
