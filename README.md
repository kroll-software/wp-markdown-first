# MD First

**Markdown-first content for WordPress.** One semantic, accessible, JavaScript‑free theme and a lightweight core plugin that make plain Markdown the single source of truth for your content.

| | |
|---|---|
| **Author** | Kroll-Software |
| **License** | MIT |
| **Release** | v1.3.30 |
| **Components** | `md-first-theme` (theme) · `md-first-core` (plugin) |
| **Requires** | WordPress 6.9+ · PHP 8.0+ (server-side only, no Node/JS toolchain) |

---

## What is MD First?

MD First flips the usual WordPress content model: instead of shaping a block editor to produce HTML, you **write — or import — clean Markdown**, and MD First renders it into fast, accessible, standards-based HTML. Math is native (LaTeX → MathML, no external engine, no client-side hydration) and the frontend ships **zero JavaScript**.

Two coordinated packages, shipped together as one release:

| Package | Type | Role |
|---|---|---|
| `md-first-theme` | WordPress theme (v1.0.0) | Semantic, accessible presentation. Pure-CSS design tokens; light/dark follows system preference. No JavaScript. |
| `md-first-core` | WordPress plugin (v1.3.30) | Server-side Markdown → HTML rendering, native math, Markdown exposure endpoints, an optional admin editor with pixel-matched live preview, and a read-only Abilities/MCP surface. |

## Features

- **Markdown is the source of truth** — `post_content` is authored, stored and served as Markdown.
- **Server-side rendering, 0 JS** — the theme ships no JavaScript at all; rendering happens in PHP.
- **Native math** — inline `$…$`, display `$$…$$` and `\[…\]` are converted to `<math>`/MathML on the server, with a graceful non-visual fallback if a formula cannot be parsed.
- **Accessible & semantic** — HTML5 structure, ARIA where meaningful, color scheme driven by `prefers-color-scheme`.
- **Markdown exposure** — `/<slug>.md` (byte-identical frontmatter + `post_content`), `/llms.txt` and `/llms-full.txt`, and `Accept: text/markdown` content negotiation on post/page URLs.
- **Read-only machine surface** — an `mdf/*` category of read-only abilities registered with the WordPress Abilities API, compatible with the official MCP adapter. No login required for the discovery endpoint; no write surface is exposed.
- **Admin editor + preview** — an optional CodeMirror-based Markdown editor whose live preview reuses the *exact same* CSS as the frontend, so what you see in the preview is what ships.
- **Translations** — German, Spanish, French, Chinese (Simplified), Japanese, Portuguese (catalogs + compiled `.mo` bundled).
- **Safe by construction** — output passes through KSES with a post whitelist plus a MathML allowlist; code spans and `$`-math are protected from accidental Markdown interpretation.

## Requirements

- WordPress **6.9+**
- PHP **8.0+**
- No JavaScript build step; no external CDN; no frontend JavaScript.

## Installation

1. **Theme** — copy `md-first-theme/` into `wp-content/themes/`, then activate **MD First** under *Appearance → Themes*.
2. **Plugin** — copy `md-first-core/` into `wp-content/plugins/`, then activate **MD First Core** under *Plugins*.
3. The theme degrades gracefully to plain content if the plugin is absent; the plugin alone adds rendering + endpoints without changing your theme.

### Reset rewrite rules (one time)

After enabling the Markdown endpoints, flush the rewrite rules so `/llms.txt` and `/<slug>.md` resolve — via *Settings → Permalinks* (save, no changes) or:

```
wp rewrite flush
```

## Project layout

```
md-first/
├── LICENSE                  # MIT
├── README.md
├── .gitignore
├── md-first-theme/          # The theme (presentation, design tokens, templates)
└── md-first-core/           # The plugin (rendering, math, endpoints, editor)
    ├── assets/              # Content + editor CSS/JS (editor uses a vendored CodeMirror)
    ├── includes/            # PHP: renderer, endpoints, abilities, admin, settings
    ├── languages/           # .po / .pot / .mo catalogs (6 locales)
    ├── vendor/              # Third-party PHP libraries (see "Licenses")
    ├── composer.json        # Declares third-party dependencies
    └── docs/                # Developer notes
```

## Markdown & math

- Standard Markdown, GFM-flavoured (tables, fenced code, task lists, footnotes).
- **Inline math** `$x^2$` · **Display math** `$$ \frac{a}{b} $$` · **LaTeX display** `\[ … \]`.
- Formulas become `<math>` (MathML) on the server; a failed conversion renders the source in a labelled fallback block rather than breaking the page.
- No external math renderer, no client-side hydration, no WebGL.

## Markdown endpoints

| Endpoint / request | Returns | Auth |
|---|---|---|
| `/<slug>.md` | YAML frontmatter + raw `post_content` (byte-identical to the database) | Public (or as post visibility dictates) |
| `/llms.txt` | Site overview for LLMs (index of endpoints) | Public |
| `/llms-full.txt` | Full digest of published posts/pages | Public |
| `GET <post/page>  ·  Accept: text/markdown` | Markdown instead of HTML (Q-value content negotiation; a convention, not an IANA type) | As post visibility dictates |

## Translations

Six locales are bundled in `md-first-core/languages/` (source `.pot`, editable `.po`, compiled `.mo`):

| Code | Language |
|---|---|
| `de_DE` | Deutsch |
| `es_ES` | Español |
| `fr_FR` | Français |
| `zh_CN` | 中文（简体） |
| `ja_JP` | 日本語 |
| `pt_PT` | Português (Portugal) |

## Extending / MCP

The plugin registers a read-only `mdf` category and a set of `mdf/*` abilities with the WordPress Abilities API (namespace `mdf/<slug>`). It is compatible with the official MCP adapter, so LLM agents can discover and read a site's Markdown directly (via `/<slug>.md`, `Accept: text/markdown`, or `/llms.txt`). The discovery/schema ability is public and read-only; no write surface is exposed.

## Non-goals

- No block-editor coupling; no dependency on a page builder.
- No frontend JavaScript and no external CDN.
- No proprietary rendering pipeline.
- No write-side machine API.

## Security notes

- All Markdown output passes KSES (post whitelist + MathML allowlist).
- The Abilities/MCP surface is **read-only**; the only public no-login endpoint is the discovery/schema one.
- The editor is an admin-only feature gated by `manage_options`-level capability on the write paths.

## License

MIT — see [`LICENSE`](LICENSE).

### Third-party dependencies

The plugin bundles the following third-party PHP libraries under `md-first-core/vendor/` (committed for a self-contained, composer-optional install). Each is distributed under its own license, which is preserved alongside the source; the vendored code is unmodified:

| Library | Version | License | Copyright |
|---|---|---|---|
| `erusev/parsedown` | 1.8.0 | MIT | Emanuil Rusev |
| `erusev/parsedown-extra` | 0.9.0 | MIT | Emanuil Rusev |
| `latex2mathml/latex2mathml-php` | v1.5.0 | MIT | Ronie Martinez |

The editor uses the vendored, minified CodeMirror (MIT) under `md-first-core/assets/editor/`. Their licenses are the authoritative terms for that code.

---

*Kroll-Software · Released as **md-first v1.3.30**.*
