# Documentation

[SPEC.md](../SPEC.md) is the product contract; [AGENTS.md](../AGENTS.md) defines engineering rules.
Application-specific guides remain in [Management docs](../apps/management/docs/) and the
[AI service README](../services/ai/README.md).

| Topic | Start here |
| --- | --- |
| Architecture and ownership | [Architecture overview](architecture/overview.md) |
| Product intent | [Product overview](product/overview.md) |
| UI and accessibility | [Management design system](design/management-design-system.md) |
| Branches and promotion | [Branch workflow](operations/branching.md) |
| AI release management | [Release Steward](operations/release-steward.md) |
| LINE integration | [Rich Menu and Flex guide](integrations/line-rich-menu-flex.md) |
| Recorded decisions | [Decisions](decisions/) |
| Proposed work | [Proposals](proposals/) |
| Delivery and verification evidence | [Reviews](reviews/) |
| Historical designs | [Archive](archive/) |

## Naming and placement

- Shared documentation uses lowercase kebab-case filenames inside topic directories.
- Dated review reports use `YYYY-MM-DD-topic.md`.
- Keep tool-discovered entrypoints such as `README.md`, `AGENTS.md`, `CLAUDE.md`, `GEMINI.md`,
  and `SPEC.md` at their conventional locations.
- Keep framework class names, migrations, dependency manifests and runtime service directories
  in their native conventions. Directory tidying must not rename database history or public APIs.
- New shared docs must be linked from this index or the appropriate topic guide.
- Component docs and existing decision/proposal identifiers retain their established paths.
- Source assets belong in `assets/`; served assets belong in the application's public directory.
- Local installations, runtime files and credentials are not documentation and must stay ignored.

## Repository locations

The GitHub repository is [business-omnichannel-ai](https://github.com/natthawat141/business-omnichannel-ai).
The existing local checkout directory has not been moved; Git remote naming and local directory
naming are independent. This preserves running tasks, local installations and deployment tooling.
