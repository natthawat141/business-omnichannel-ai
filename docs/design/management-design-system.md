# Management Design System

## Direction

Quiet workspace inspired by Notion: low-chroma surfaces, clear type hierarchy, compact
controls, and no decorative color. Light mode is the default; a user-controlled dark mode
uses the same neutral hierarchy and persists per browser. The interface should feel calm
and easy to read at the point where an operator is editing business data.

## Color tokens

| Role | Token | Value |
| --- | --- | --- |
| App background | `--mg-bg` | `#f8fafc` |
| Secondary surface | `--mg-surface-1` | `#f1f5f9` |
| Primary surface | `--mg-surface-2` | `#ffffff` |
| Raised surface | `--mg-surface-3` | `#f8fafc` |
| Border | `--mg-border` | `#e2e8f0` |
| Strong border | `--mg-border-strong` | `#cbd5e1` |
| Primary ink | `--mg-ink` | `#0f172a` |
| Secondary ink | `--mg-ink-muted` | `#475569` |
| Tertiary ink | `--mg-ink-subtle` | `#64748b` |
| Focus | `--mg-focus` | `#334155` |

Semantic colors remain reserved for feedback: red for errors and destructive actions,
amber for warnings, and green only for successful state. Blue is not used as product chrome.

## Typography

Use one family, Noto Sans Thai with system fallbacks. Keep headings compact, labels medium,
body text readable, and avoid all-caps UI copy. Thai and English labels share the same scale.

## Components

- Buttons: 40px minimum height, 6px radius, one primary treatment, clear disabled state.
- Inputs: 40px minimum height, 6px radius, white surface, visible focus ring, inline error text.
- Cards and tables: 8px radius, border-led separation, no decorative drop shadow.
- Sidebar: secondary surface with one selected navigation row and a visible light/dark theme toggle.
- Forms: grouped sections with consistent label, hint, field, and action spacing.

## Responsive behavior

The sidebar collapses to the existing mobile drawer below the large breakpoint. Form grids
collapse to one column on small screens. Tables retain horizontal scrolling rather than
shrinking text below readable sizes.

## Accessibility

Target WCAG 2.2 AA contrast, preserve visible keyboard focus, keep semantic status text in
addition to status color, and respect reduced-motion preferences.
