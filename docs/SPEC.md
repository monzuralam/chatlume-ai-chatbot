# Build "Chatlume – AI Chatbot": a multi-provider AI chatbot plugin for WordPress

## How to use this spec
This file is the single source of truth for the project. Keep it in the repository root (as `SPEC.md`, or `CLAUDE.md` for Claude Code). Build in the order given under "Build phases". Before each phase, re-read the modules it touches. At the end of each phase, list the files created or changed, explain how to test the phase manually, and confirm everything works before moving on.

## Role
You are a senior WordPress plugin engineer and product designer. Build a production-ready, secure plugin that will be submitted to the WordPress.org Plugin Directory and must pass its review on the first attempt. Write complete, working code with no placeholders, stubs, or "rest of code here" comments.

## Plugin identity
- Plugin name: `Chatlume – AI Chatbot` (identical in the main file header and the readme title).
- Slug, folder name, and text domain: `chatlume-ai-chatbot`. Main file: `chatlume-ai-chatbot.php`.
- PHP namespace `Chatlume`; bundled vendor namespace `Chatlume\Vendor\`.
- Prefix `chatlume_` for every function, option, transient, hook, cron event, post/user meta key, and nonce action. Constants `CHATLUME_*` (e.g. `CHATLUME_VERSION`, `CHATLUME_OPENAI_API_KEY`).
- REST namespace `chatlume/v1`; database tables `{prefix}chatlume_*`; admin page `admin.php?page=chatlume`; script/style handles `chatlume-*`.
- Frontend: custom element `<chatlume-widget>`, CSS custom properties `--chatlume-*`, JS globals `window.Chatlume` and `window.chatlume.admin`, shortcode `[chatlume]`, block `chatlume/chat`, abilities `chatlume/*`, uploads folder `uploads/chatlume-fonts/`.
- No generic names anywhere. Never use `wp_`, `__`, or `_` as a prefix.
- Third-party names (OpenAI, ChatGPT, Claude, Gemini, WooCommerce) appear only after connectors such as "for", "with", or "using", never at the start of a name. No third-party logos are bundled.

## Product summary
Chatlume lets a site owner add a smart AI chatbot to their WordPress site in under 3 minutes. It has three core pillars:
1. A **premium, user-friendly admin dashboard** with a setup wizard, a visual Customizer with always-on live preview, a test playground, analytics, and conversation logs.
2. **Multiple AI providers**: OpenAI (ChatGPT), OpenRouter, Anthropic (Claude), Google Gemini, Groq, Mistral, DeepSeek, xAI, Together, Ollama (local), any custom OpenAI-compatible endpoint, and, on WordPress 7.0+, providers already connected in WordPress core.
3. A **beautiful, fast, accessible, fully brandable chat widget** with streaming responses.

## Competitive goals (hard requirements)
The main competitor is a long-established, feature-heavy chatbot plugin with a legacy codebase. Chatlume must win on quality, reliability, and the generosity of its free version:
- **Zero theme, page-builder, and optimization-plugin conflicts**: the widget lives in Shadow DOM, adds no global CSS or JS, and keeps working with JS combine/delay features enabled.
- **Effortless branding**: colors, typography, logo, layout, and every visitor-facing text are customizable visually, with instant live preview and a one-click "Match my website".
- **Cache-proof settings**: changes appear on the frontend without anyone clearing browser cache or cookies.
- **Clear, actionable errors**: when a provider fails, visitors get a graceful answer within seconds, never an endless loading state, and admins see the exact cause and how to fix it.
- **Works without an API key**: quick answers and site-search results from minute one.
- **Streaming for every provider**, not just one.
- **New models appear automatically** through live model lists, with no plugin update needed.
- **Cost control built in**: quick answers that skip the AI, response caching, rate limits, and budget caps. (WordPress core's AI Client has no cost controls or rate limits; Chatlume provides them.)
- **Included free**: replies in the visitor's language, voice input and read-aloud, document uploads, proactive triggers, webhooks, and source links under answers.
- **Modern codebase**: PHP 8.0+, TypeScript, React, automated tests, WordPress Coding Standards.
- **No upsell banners, sale notices, review nags, or deactivation surveys.**

## Platform strategy
- **WordPress 6.5+** is fully supported using Chatlume's own provider layer.
- **WordPress 7.0+** gets progressive enhancements: the core AI Client and Connectors (Settings > Connectors) as a key source, and the core Command Palette for navigation.
- **WordPress 6.9+** gets Chatlume abilities registered with the Abilities API.
- The WordPress Font Library and theme.json fonts and colors are used by the Customizer where available.
- Detect every core feature with `function_exists()` / `class_exists()` checks. Never cause a fatal error on older versions.
- Verify current core API names and signatures in the official developer documentation (developer.wordpress.org and make.wordpress.org/core). Do not guess.

## Technical requirements
- WordPress 6.5+ (tested up to the latest stable release), PHP 8.0+. No PHP notices or warnings with `WP_DEBUG` on.
- PHP: namespaced classes, PSR-4 autoloading via Composer, WordPress Coding Standards. All bundled Composer dependencies are prefixed with **Strauss** (or PHP-Scoper) under `Chatlume\Vendor\` to prevent conflicts with other plugins. The release zip includes a working autoloader.
- **Admin UI**: a premium, custom-designed React single-page app in TypeScript, NOT built from `@wordpress/components`. It uses WordPress core's React through wp-scripts dependency extraction. Stack: Tailwind CSS (without its global preflight), shadcn/ui-style components built on Radix UI primitives, lucide-react icons, Recharts for charts, Motion (formerly Framer Motion) for transitions, TanStack Query for server state (wrapping `wp.apiFetch`), React Hook Form + Zod for forms, Sonner for toasts, `@wordpress/commands` for Command Palette integration, an OKLCH color library (tree-shaken, admin only), and HashRouter for in-app routes. Routes are code-split with `React.lazy` so heavy libraries load only where needed.
- **Widget**: written in React (TypeScript, JSX, hooks), compiled against Preact via `preact/compat` aliasing, so the source is standard React but the shipped runtime is Preact (core does not ship Preact, so bundling it is allowed; note this in a code comment). Rendered inside a **Shadow DOM**. Styled with hand-written CSS using custom properties (no Tailwind). Zero WordPress script dependencies. Size budget: launcher under 6 KB gzipped, full chat app under 45 KB gzipped.
- All assets bundled locally. No external CDNs, fonts, images, or scripts.
- Pin exact major versions of every npm and Composer dependency.
- All text handling uses multibyte-safe `mb_*` functions.
- Settings stored in a single option array (`chatlume_settings`, autoload off) plus a small autoloaded `chatlume_widget_config` for the frontend launcher. Settings carry a schema version with migrations.
- Custom database tables via `dbDelta`, with a DB version option and upgrade routine.
- **Multisite**: settings, tables, and indexes are per site. Network activation creates tables for all existing sites and for new sites (via `wp_initialize_site`).

## Build tooling
- Use `@wordpress/scripts` (wp-scripts, webpack-based) for all JavaScript and CSS. Do NOT use Vite.
- Provide a custom `webpack.config.js` that extends the default wp-scripts config and exports separate configurations:
  1. **admin**: entry `src/admin/index.tsx` → `build/admin/`. Keep the default Dependency Extraction plugin so `react`, `react-dom`, and `@wordpress/*` resolve to WordPress core globals. PHP enqueues it using `build/admin/index.asset.php` for dependencies and version.
  2. **widget**: entry `src/widget/index.ts` → `build/widget/`. Alias `react`, `react-dom`, and `react/jsx-runtime` to `preact/compat` and `preact/jsx-runtime`, and configure the Dependency Extraction plugin so it does NOT externalize react/react-dom. Set the public path at runtime from a PHP-provided plugin URL (`__webpack_public_path__`) so lazy chunks load correctly even when optimization plugins move or combine scripts. Import widget CSS as a string (css-loader with `exportType: 'string'` via an `?inline` resource query, plus postcss-loader) and inject it into the shadow root. Make sure the default wp-scripts CSS rule excludes `?inline` imports so both rules never apply.
  3. **block**: the Gutenberg block in `blocks/chat/` built with the standard wp-scripts block setup.
- TypeScript compiles through wp-scripts' Babel setup; `tsc --noEmit` handles type-checking.
- Tailwind CSS (admin only) is added through PostCSS (`postcss.config.js`). Verify this works with the installed wp-scripts version.
- All admin code and libraries must be compatible with the React version bundled in the minimum supported WordPress version and in the latest release.
- npm scripts:
  - `npm run start`: `wp-scripts start --hot` (requires `SCRIPT_DEBUG` true); fall back to `wp-scripts start` if Fast Refresh is unavailable.
  - `npm run build`: `wp-scripts build` with the custom config.
  - `npm run lint`: `wp-scripts lint-js`, `wp-scripts lint-style`, and `tsc --noEmit`.
  - `npm run format`: `wp-scripts format`.
  - `npm run lint:php`: PHPCS with WordPress-Extra and PHPCompatibilityWP.
  - `npm run test`: `wp-scripts test-unit-js` (Jest); `composer test` for PHPUnit.
  - `npm run i18n`: WP-CLI `wp i18n make-pot` and `wp i18n make-json --use-map`.
  - `npm run zip`: `wp-scripts plugin-zip`, using the `files` field in package.json. The zip includes runtime files **plus** `src/`, `blocks/`, `webpack.config.js`, `postcss.config.js`, `tsconfig.json`, `package.json`, and `composer.json` (WordPress.org requires source for compiled code). It excludes `node_modules`, `.git`, tests, source maps, and OS junk files.
- Composer scripts run Strauss automatically after `composer install` / `composer update`.
- The widget receives its UI strings from PHP, so it needs no JS translation files.
- Nothing in `/build` or the unprefixed `/vendor` is committed to git.

## File structure
```
chatlume-ai-chatbot/
├── chatlume-ai-chatbot.php, uninstall.php, readme.txt
├── composer.json, package.json, webpack.config.js, postcss.config.js, tsconfig.json
├── data/model-suggestions.json, data/presets/ (theme preset JSON files)
├── assets/fonts/ (bundled open-license woff2 fonts)
├── includes/
│   ├── Plugin.php
│   ├── Installer/Schema.php, Migrations.php, Multisite.php, Activation.php
│   ├── Admin/AdminPage.php, Assets.php, SetupWizard.php, HealthCheck.php
│   ├── Appearance/ThemeSchema.php, TokenResolver.php, PresetLibrary.php,
│   │       BrandDetector.php, FontManager.php, PreviewMode.php
│   ├── REST/SettingsController.php, ProvidersController.php, ChatController.php,
│   │       PlaygroundController.php, AppearanceController.php,
│   │       ConversationsController.php, KnowledgeController.php,
│   │       QuickAnswersController.php, StatsController.php
│   ├── Providers/ProviderInterface.php, AbstractProvider.php,
│   │       OpenAICompatibleProvider.php, AnthropicProvider.php, GeminiProvider.php,
│   │       CoreConnectorsBridge.php, ProviderRegistry.php, ProviderError.php,
│   │       ModelSuggestions.php, HttpStreamer.php
│   ├── Chat/ChatService.php, ConversationState.php, PromptBuilder.php,
│   │       StreamHandler.php, StreamLimiter.php, ResponseCache.php, CostGuard.php
│   ├── QuickAnswers/Matcher.php
│   ├── Knowledge/Indexer.php, Chunker.php, Retriever.php, Embeddings.php,
│   │       NgramScorer.php, DocumentParser.php, SiteSearchFallback.php
│   ├── Security/Encryption.php, RateLimiter.php, SessionManager.php, IpResolver.php
│   ├── Data/ConversationRepository.php, MessageRepository.php
│   ├── Frontend/WidgetLoader.php, Shortcode.php, DisplayRules.php
│   ├── Integrations/Webhooks.php, Notifications.php
│   ├── Abilities/AbilitiesRegistrar.php
│   ├── Compat/CachePurger.php, OptimizationPlugins.php, Multilingual.php, WooCommerce.php
│   ├── Privacy/PrivacyHandler.php
│   └── Cron/RetentionCleanup.php, IndexQueue.php
├── src/
│   ├── admin/   (index.tsx, App.tsx, routes/ incl. customizer/, components/ui/,
│   │             hooks/, lib/palette.ts, extensions/registry.ts, commands.ts, styles/)
│   ├── shared/theme/ (schema and token names shared by admin and widget)
│   └── widget/  (index.ts = tiny launcher, app/ = React chat app, lib/stream.ts,
│                 lib/markdown.ts, lib/voice.ts, lib/tabs.ts, lib/events.ts,
│                 lib/preview.ts, styles/widget.css)
├── blocks/chat/ (block.json, edit.tsx, render.php)
├── build/, languages/
└── tests/       (php/, js/)
```

## Module 1: Provider layer
- `ProviderInterface` methods: `get_id()`, `get_label()`, `get_settings_schema()`, `list_models()`, `chat( array $messages, array $options ): ChatResponse`, `stream( array $messages, array $options, callable $on_token ): ChatResponse`, `embed( array $texts, array $options ): array`, `test_connection()`, `supports( string $feature )` (features: `streaming`, `embeddings`, `embedding_dimensions`, `tools`).
- Normalized message format (`role`, `content`) and normalized response (`text`, `input_tokens`, `output_tokens`, `finish_reason`, `latency_ms`, `raw`).
- One `OpenAICompatibleProvider` base class with presets: OpenAI (`https://api.openai.com/v1`), OpenRouter (`https://openrouter.ai/api/v1`, with optional `HTTP-Referer` and `X-Title` headers), Groq, Mistral, DeepSeek, xAI, Together, Ollama (`http://localhost:11434/v1`, no key), and "Custom endpoint".
- Native adapters for Anthropic (Messages API, `x-api-key` and `anthropic-version` headers, top-level system prompt; no embeddings) and Google Gemini (`generateContent` / `streamGenerateContent?alt=sse`, plus embeddings).
- All requests go through the WordPress HTTP API (see Module 10).
- **Never hardcode model lists.** Fetch models from each provider's models endpoint, cache for 12 hours, provide "Refresh models", and allow typing a custom model ID. Verify endpoints against current official docs if you have web access.
- **Recommended models**: `data/model-suggestions.json` (shipped with each release, never fetched remotely) is used only to add a "Recommended" badge and sort order. The live list is always the source of truth; suggestions missing from it are ignored. Highlight free models where the live list reports zero pricing (e.g. OpenRouter).
- Per-provider settings: API key, base URL, default chat model, timeout, optional extra headers.
- **Embeddings provider**: a separate setting, independent of the chat provider, because some providers (such as Anthropic) offer no embeddings. Defaults to the chat provider when it supports embeddings. Request reduced dimensions (256–512) where supported.
- **WordPress Connectors bridge (7.0+)**: detect providers connected in Settings > Connectors and offer them as one-click options in the wizard and Providers page, labeled "Connected in WordPress". Use the core AI Client for requests where it supports the needed feature; use Chatlume's own layer for streaming and for providers core does not cover. Never copy core-stored credentials into Chatlume's own storage.
- "Test connection" sends a tiny prompt and reports success, latency, and a human-readable result.
- `ProviderError` normalizes failures (invalid key, out of credits/quota, rate limited, model not found, context too long, timeout, server error, key unreadable), each with a plain-language admin explanation and a fix link (e.g. the provider's billing or API keys page). Visitors only see a friendly message.
- **Primary + fallback** provider/model: on error or timeout, retry once with the fallback.
- Registry extensible via the `chatlume_register_providers` filter.

## Module 2: Chat pipeline and streaming

### Conversation state
- The server is the source of truth. The client sends only the new message, session token, and conversation ID; the server loads history itself. Never accept system or assistant messages from the client.
- `localStorage` is used only to restore the visible conversation after a reload.
- When conversation storage is disabled, history lives in a short-lived transient (expires after 30 minutes idle) and nothing is written to the database tables.
- Multiple tabs stay in sync via the `storage` event or BroadcastChannel; only one tab streams at a time.

### Pipeline (every visitor message, in order)
1. Validate the session token, rate limits, budget caps, input length, and blocked words.
2. **Quick answers**: if the message matches a quick answer above the confidence threshold, reply instantly without calling the AI.
3. **Response cache**: for first-turn questions only, from logged-out visitors, return a cached AI answer if the same normalized question (same language, same resolved system prompt hash) was answered within the TTL and the knowledge base hasn't changed since.
4. Retrieve knowledge context.
5. Build the prompt and call the provider (streaming or not). On failure, try the fallback provider/model.
6. If the AI is unavailable, unconfigured, or over budget, use the **site-search fallback**: a short friendly message plus the top 3 matching pages as link cards.
7. Log the message with its source (`ai`, `quick_answer`, `cache`, `fallback_model`, `site_search`), tokens, estimated cost, and latency; fire hooks; return source links.

### No-key mode
With no provider configured, the bot still works using quick answers and the site-search fallback. The setup wizard offers "Skip AI for now".

### REST routes (namespace `chatlume/v1`)
- Public: `POST /session` (create or restore a visitor session; returns a signed token plus fresh behavior config), `POST /chat`, `POST /chat/stream`, `POST /feedback`, `POST /handoff` (email the transcript to the site team).
- Admin: `GET/POST /settings`, `GET /providers` (including core-connected providers), `GET /providers/{id}/models`, `POST /providers/{id}/test`, `POST /playground/chat` (returns debug data), `GET/POST /appearance/draft`, `POST /appearance/publish`, `GET /appearance/versions`, `POST /appearance/versions/{id}/restore`, CRUD for `/appearance/presets`, `POST /appearance/preview-token`, `GET /appearance/brand`, `POST /appearance/fonts`, CRUD for `/quick-answers` and `/knowledge/sources`, `POST /knowledge/upload`, `POST /knowledge/reindex`, `GET /knowledge/status`, `GET/DELETE /conversations`, `GET /conversations/{id}`, `GET /conversations/export`, `GET /stats`, `GET /health`, `POST /webhooks/test`.

### Streaming
- `HttpStreamer` uses the WordPress HTTP API and receives chunks through its low-level hooks (the `requests-request.progress` action, or the `http_api_curl` action to attach a write callback). It parses each provider's stream format and re-emits normalized SSE events: `token`, `sources`, `done` (usage and message ID), and `error` (error type and visitor-safe message). Only if this proves impossible in testing may raw cURL be used, isolated in this one class with a comment explaining why for reviewers.
- Send `Content-Type: text/event-stream`, `Cache-Control: no-cache`, `X-Accel-Buffering: no`; clear output buffers, flush after each chunk, exit cleanly.
- Call `session_write_close()` if any PHP session is open before streaming.
- In the chunk callback, check `connection_aborted()` and abort the upstream request (by throwing a dedicated exception that is caught around the request) when the visitor disconnects, so no tokens are wasted.
- **Server capacity**: a "Max concurrent streams" setting (default 3) protects small hosts; excess requests use non-streaming. Each request has a maximum duration (default 60 seconds) that always stays below the host's `max_execution_time`. Never change PHP runtime settings to extend it.
- If the server buffers responses, the client detects it and falls back to non-streaming automatically. Admin toggle to disable streaming.
- The widget uses `fetch()` with a `ReadableStream` reader and supports aborting via "Stop".
- **Latency budget**: everything before the provider call (validation, quick answers, cache, retrieval) completes in under 300 ms on shared hosting with ~5,000 knowledge chunks.

### Page-cache compatibility
Never print nonces or tokens into cached HTML. The widget gets its session token at runtime from `/session`.

## Module 3: Chatbot behavior
- **Persona**: bot name, avatar, system prompt with variables `{site_name}`, `{site_url}`, `{page_title}`, `{page_url}`, `{date}`, `{user_name}`. Presets: Customer Support, Sales Assistant, FAQ Bot, Blank. Write high-quality default prompts for each preset (concise answers, light markdown, honest "I don't know", reference sources) plus polished default welcome messages and starter questions.
- **Parameters**: temperature, max output tokens, history window, max visitor message length.
- **Language**: reply in the visitor's language by default, with an option to force one language. Compatible with WPML and Polylang: detect the current language, filter knowledge and quick answers by language, and allow widget strings per language.
- **Guardrails**: "Only answer questions about this website" toggle, refusal message, blocked words list.
- **Quick answers**: admin-defined question/answer pairs with optional keywords and language. Matched with normalized, multibyte-safe token scoring against a high default threshold. When a quick answer is served, the widget shows a small "Not what you meant? Ask AI" action that re-sends the question to the AI and records a miss. Optionally shown as starter chips. Hit counts tracked.
- **Cost controls**: per-conversation message cap, daily and monthly budget caps (tokens or estimated currency), and a chosen behavior when a cap is reached (quick answers and site search only, offline message, or switch to the fallback model). Admin email alerts at 80% and 100% of budget.
- **Knowledge base**:
  - Sources: free-text business info; selected post types and individual posts/pages; WooCommerce products if active (title, short description, price, stock status, URL); uploaded documents (PDF, TXT, MD, CSV, JSON, DOCX). Extract text on upload (`smalot/pdfparser` for PDF, prefixed by Strauss), store only extracted chunks, and never keep original files in a publicly accessible location. Configurable max file size.
  - Indexer: strip HTML and shortcodes, split into ~800-character chunks with overlap, store with title, URL, and language. Re-index on `save_post` / `delete_post`, and for WooCommerce also on stock and price changes. Large sites are processed in WP-Cron batches with progress shown in the admin.
  - Embeddings: store each vector as packed binary along with the embedding model ID and dimension. When the embeddings model changes, mark chunks stale and re-embed in the background; never mix models. Cache query embeddings for repeated questions.
  - **Two-stage retrieval**: keyword search (MySQL FULLTEXT) selects the top ~100 candidates, then semantic reranking with embeddings runs on those candidates only. Never load all vectors per request. For languages without word spaces (Chinese, Japanese, Thai) or when FULLTEXT is unavailable, use PHP n-gram scoring instead. Inject the top K chunks inside clear delimiters with an instruction to answer from that context and to say so when the answer isn't there.
  - The Knowledge page shows index size and warns when nearing a sensible limit for the hosting environment.
  - **Source links**: when an answer uses site content, return up to 3 sources (title and URL) for display under the answer.
- **Lead capture** (optional): pre-chat form or ask for name/email after N messages; stored with the conversation; optional admin email notification.
- **Human handoff** (optional): buttons linking to a contact page, email, phone, or WhatsApp, plus "Email this conversation to the team".

## Module 4: Admin dashboard
Top-level menu "Chatlume" with a custom SVG icon, built as a React single-page app with a left sidebar.

### Pages
1. **Setup wizard** (opens once after activation; see Module 10 for redirect rules): choose provider (core-connected providers shown first as one-click options) → step-by-step guide for that provider (create a key, add billing, direct links) → paste key and test → pick model (recommended and free models highlighted) → pick a brand color or use "Match my website", and a position → done. A live widget preview is visible at every step. "Skip AI for now" sets up no-key mode.
2. **Dashboard**: connection health, stats for today/7/30 days (conversations, messages, tokens, estimated cost, money saved by quick answers and cache), a chart, recent conversations, **"questions your bot couldn't answer"** (no knowledge match, site-search fallback, "Ask AI" misses, or thumbs down) with a one-click "Create quick answer" action, and a setup checklist.
3. **Providers**: card grid with neutral icons or initials (no trademarked logos), status badges, slide-out configuration panel, test button, "Set as primary / fallback", and the separate embeddings provider setting.
4. **Chatbot**: persona, presets, system prompt editor with variable helper, parameters, language, guardrails, cost controls, and a compact preview drawer.
5. **Knowledge**: tabs for Sources, Documents (drag-and-drop upload with parsing status), Quick answers (inline editing, CSV import/export), and Index status (progress, size, stale embeddings, "Re-index all").
6. **Playground** ("Test your bot"): chat as a visitor inside the admin. Each reply has a debug panel showing the pipeline source (quick answer, cache, AI, fallback), retrieved chunks with scores, the final prompt (never keys), tokens, cost, latency, and provider/model. Actions: "Save as quick answer" and "Improve this answer" (edit the reply to create a quick answer).
7. **Customizer**: see Module 5.
8. **Display & triggers**: enable site-wide, include/exclude pages, post types, devices, logged-in/out visitors, roles, business hours with an offline message, and proactive triggers (time on page, scroll depth, exit intent, specific pages, frequency capping). Show copy-ready shortcode and block instructions.
9. **Conversations**: searchable, filterable table (date, rating, source, has lead, language), transcript side panel with sources and per-message cost, delete, bulk delete, export CSV/JSON.
10. **Integrations**: webhooks (events: lead captured, conversation ended, negative feedback, handoff requested) with a test button and the last 50 deliveries; email notification settings; analytics events toggle.
11. **Settings**: rate limits, trusted proxy (none / Cloudflare / custom IP list), max concurrent streams, retention, privacy, streaming toggle, response cache TTL, debug logging, delete-data-on-uninstall, import/export settings JSON, and a **Health check** panel (PHP version, HTTP API transport, libsodium, WP-Cron, REST API reachability, live streaming test, FULLTEXT support, detected caching and optimization plugins, WordPress AI Client availability) with a plain-language fix for each failed check, plus a provider error log with explanations.

### Admin design system (premium look)
- Visual direction: modern SaaS dashboard quality (think Linear, Stripe, Vercel). Generous whitespace, 12–16 px radius cards with subtle 1 px borders and soft shadows, a refined neutral palette with one accent color, clear typographic hierarchy, consistent 4/8 px spacing. It should feel at home next to the modernized WordPress 7.0 admin.
- Font: system font stack, or a bundled open-license font such as Inter (woff2, local).
- Light and dark mode for the admin app, remembered per user (user meta).
- Layout: the app fills the wp-admin content area with its own header (logo, plugin name, status indicator, docs link) and icon-based left navigation. On Chatlume screens only, hide other plugins' admin notices.
- CSS isolation: Tailwind configured with a class prefix and without its global preflight; a small reset scoped to `#chatlume-admin` replaces it, and wp-admin form/button styles are neutralized inside the app root.
- Radix portals (dialogs, dropdowns, popovers, tooltips) render into a container inside `#chatlume-admin`, not document.body.
- Interactions: skeleton loaders, optimistic updates, smooth transitions (respecting `prefers-reduced-motion`), toasts, a sticky save bar with an unsaved-changes warning (the Customizer uses Save draft / Publish instead), and Cmd/Ctrl+S to save.
- **Command Palette**: do NOT build a custom palette. Register Chatlume navigation commands ("Chatlume: Conversations", "Chatlume: Test your bot", "Chatlume: Customize widget", etc.) with `@wordpress/commands` so they appear in the core palette.
- Every empty state has a custom SVG illustration, one-line explanation, and a clear call to action. Inline validation on every form; tooltips on non-obvious settings.
- Responsive down to tablet width, keyboard accessible, WCAG AA contrast, every string translatable.

### Extension points for add-ons
Expose an admin JS registry so a future add-on plugin can extend the app without modifying it: `window.chatlume.admin.registerPage()`, `registerSettingsSection()`, and `registerDashboardCard()`.

## Module 5: Widget Customizer
Goal: a non-technical site owner can make the widget match their brand in under 2 minutes, while designers and developers still get full control.

### Screen layout
- Three-part layout like a modern theme editor: a left panel with collapsible sections, a center live preview, and a top bar containing the preset picker, undo/redo (Cmd/Ctrl+Z, Shift+Cmd/Ctrl+Z), device toggle, light/dark toggle, RTL toggle, "Preview on my site", "Reset", and Save draft / Publish.
- **Simple / Advanced toggle**: Simple shows only brand color, logo/avatar, font, position, and preset. Advanced reveals every option below.
- **Draft and publish**: edits autosave as a draft; visitors see changes only after "Publish". Show an "Unpublished changes" badge. Keep the last 10 published versions with one-click restore.
- **Preview on my site**: opens the homepage (or a chosen URL) in a new tab with the draft applied via a signed, short-lived preview token, visible only to the logged-in admin.

### Live preview (always on while customizing)
- **Always visible**: the preview fills the center and stays visible (sticky) while the admin scrolls the settings panel. Below 1280 px the settings panel narrows; below 1024 px a "Show preview" toggle overlays it. The admin never has to save to see a change.
- **Real website backdrop**: the widget appears on top of the admin's actual site, loaded in a same-origin iframe (homepage by default; any page selectable from a URL picker). The iframe URL carries a signed, admin-only, 30-minute preview parameter that hides the admin bar, suppresses the live widget on that page so only one widget shows, and mounts the widget in preview mode. If the page can't be framed (X-Frame-Options, CSP, redirects, or errors), automatically fall back to a neutral backdrop (light, dark, pattern, or custom color) with a short notice explaining why. While the iframe loads, show a skeleton backdrop; the widget itself renders immediately.
- **Instant updates**: every change is sent to the preview via `postMessage` (origin-checked, batched once per animation frame) and applied with no reload. Color, size, radius, and font changes update CSS custom properties directly, so dragging a color picker or slider stays smooth at 60 fps. Structural changes re-render only the affected component.
- **Stays open**: the preview widget is pinned open while customizing, and its conversation and scroll position survive every setting change. A "Show launcher" button closes it to preview the launcher and teaser.
- **Context-aware**: when the admin focuses a control, the preview switches to the state where that element is visible and briefly highlights it with a soft outline pulse (launcher settings show the closed launcher and teaser; bubble colors show a conversation; lead form labels show the lead form; the offline message shows the offline state). An "Auto-switch preview" toggle turns this off. A manual state switcher stays available: closed launcher, empty state, conversation (rich markdown, list, code block, source cards, quick answer with "Ask AI"), streaming, typing, error with retry, lead form, offline. Samples use the admin's real bot name, avatar, welcome message, and starter questions.
- **Click to edit**: hovering any element in the preview shows an outline with its name; clicking it opens the matching settings section and focuses the control.
- **Interactive**: the admin can type in the preview. "Sample replies" (default) returns canned replies with no API cost. "Live AI replies" uses the real bot through the admin-only Playground endpoint; these chats are labeled as tests and excluded from Conversations and stats.
- **Before / After**: a toggle (or press-and-hold) swaps between the published version and the current draft.
- **Devices**: desktop, tablet, and mobile (375×812 with safe areas, showing real mobile full-screen behavior).

### One-click branding
- **"Match my website"**: pulls the site icon or custom logo as the avatar, the theme.json color palette, and theme fonts via WordPress global settings. For classic themes without theme.json, sample computed styles (body font, text color, link color, button background) from the homepage in a hidden same-origin iframe. Show a before/after so the admin can accept or tweak.
- **Theme presets**: at least 10 curated presets with thumbnails (e.g. Minimal, Midnight, Glass, Corporate, Soft, Playful, Mono, Ocean, Sunset, Forest), each a complete token set. A "Keep my brand color" switch applies the admin's brand color to any preset.
- "Save as my preset", import/export a theme as JSON, reset one section or everything.

### Brand
- Bot name, avatar (Media Library upload with circle/square crop, initials on a color, or built-in illustrated avatars), optional company logo in the header, header title and subtitle, online status text.
- **Launcher**: built-in SVG icon set (at least 12), the avatar image, or a custom upload; shape (circle, rounded square, or pill with a text label such as "Chat with us"); size (S/M/L); icon color.
- **Attention effect**: none, single pulse, gentle bounce once, or badge counter; teaser bubble style.
- "Powered by Chatlume" credit: off by default; shown only if the admin opts in.

### Colors
- **Simple mode**: pick one brand color and the admin generates the full palette for light and dark mode using OKLCH-based color math: primary, hover/active shades, text on primary, surfaces, borders, bubble colors, focus ring.
- **Advanced mode**: edit every semantic token separately for light and dark: primary, on-primary, accent, background, surface, header background (solid or two-stop gradient with angle), header text, bot bubble background/text, user bubble background/text, links, borders, input background/text, launcher background/icon, source cards, error, success.
- **Color picker**: swatch, hex/RGB/HSL input, eyedropper (EyeDropper API where supported), theme palette swatches, recently used colors.
- **Color mode**: light, dark, or auto (follows the visitor's OS). The dark palette is generated from the light one, with per-token overrides.
- **Contrast checker**: every text/background pair is tested against WCAG AA. Failures show an inline warning with "Fix automatically", which adjusts lightness to the nearest passing value.
- All color math runs in the admin; the widget receives only resolved values.

### Typography
- **Font sources**: inherit from the site; system font stacks; fonts from the active theme (theme.json); fonts installed in the WordPress Font Library; a curated set of bundled open-license variable fonts (e.g. Inter, DM Sans, Manrope, Plus Jakarta Sans, Lora, plus Noto Sans Bengali and Noto Sans Arabic), loaded only when selected; or a custom woff2 upload.
- Separate body font and optional heading font.
- Base size (13–18 px, default 15 px), line height, header title weight, message weight, and letter spacing (Advanced only).
- The message input is always at least 16 px on mobile so iOS does not zoom in on focus.
- Every font choice includes a fallback stack covering scripts the font lacks (Bengali, Arabic, CJK). Show a "script support" note when the chosen font has no glyphs for the site's language.
- **Shadow DOM font loading**: register `@font-face` rules at the document level (via the FontFace API / `document.fonts`), because `@font-face` declared inside a shadow root is ignored by some browsers. Load only fonts in use, with `font-display: swap`.

### Layout and shape
- **Widget style**: floating window (default), side panel (full-height drawer), centered modal, or inline (block/shortcode).
- **Position**: bottom-right or bottom-left; X/Y offsets set separately for desktop and mobile; z-index override.
- **Size**: desktop width and height within safe min/max limits; mobile full-screen toggle.
- **Corner radius** presets (Sharp, Rounded, Soft, Pill), with per-element overrides in Advanced (window, bubbles, buttons, input, cards).
- **Shadow**: none, soft, medium, strong. **Density**: compact, comfortable, spacious.
- **Header style**: solid, gradient, or minimal (transparent with border); show/hide avatar and status dot.
- **Message style**: bubbles (default), flat (full-width, document-like), or outlined bubbles; bot avatar on each message on/off; timestamps on hover, always, or never.
- **Background**: solid, subtle pattern (4 built-in SVG patterns at low opacity), or gradient. Glass effect (backdrop blur) for presets that use it, with an automatic solid fallback where unsupported.
- **Composer**: rounded field or full-width bar; send button as icon or text.

### Motion
- Open animation: scale-fade, slide-up, or none; speed (fast, normal, slow); message entrance animation on/off. Always respect `prefers-reduced-motion`.

### Content
- Welcome message (bold, links, emoji), up to 4 starter questions, quick action buttons, input placeholder, offline message, error message, consent text, lead form labels, and every other visitor-facing string, editable per language when WPML or Polylang is active.

### Developer styling (no custom CSS field)
- Chatlume does NOT store or output admin-entered CSS.
- A "Developer styling" panel shows copy-ready snippets using `--chatlume-*` custom properties and `::part()` selectors, with instructions to paste them into WordPress's own Additional CSS (Customizer or Site Editor > Styles). Custom properties inherit into the Shadow DOM, and `part` attributes are exposed on key elements (launcher, window, header, message-bot, message-user, composer, source-card).
- "Copy theme tokens as CSS" button.

### Design token system
- All appearance settings form one versioned `theme` JSON object (with schema migrations), validated with Zod in the admin and a matching PHP validator. Every numeric input is clamped to safe ranges so no setting can break the layout.
- PHP resolves the theme into flat CSS custom properties for light and dark mode, stored in the autoloaded `chatlume_widget_config` (kept under ~6 KB), so the launcher renders correctly on first paint with no flash of default styles.
- The widget applies tokens on the shadow host; switching light/dark swaps token sets without re-rendering.
- Images use Media Library URLs at appropriate sizes (avatar 96–128 px) with srcset for high-density screens.

## Module 6: Chat widget
Quality bar: comparable to Intercom or Crisp. Every visual property below is a default the Customizer (Module 5) can change.
- **Launcher**: default 56–60 px circle, soft layered shadow, hover lift, open/close icon morph, unread dot, optional teaser bubble.
- **Window**: default ~380×600 px floating window with 16 px radius and a layered shadow; also side panel, centered modal, and inline styles. Header with avatar, bot name, online indicator, and a menu (new conversation, email transcript, close).
- **Messages**: bot messages left with avatar, user messages right. Markdown (bold, italic, lists, links, inline code, code blocks with copy) rendered through an **allowlist-based sanitizer**; never `innerHTML` or `dangerouslySetInnerHTML` with unsanitized model output. Links open in a new tab with `rel="noopener noreferrer"`.
- **Source cards**: up to 3 compact link cards (title and domain) under answers that used site content, and for site-search fallback results.
- **Streaming**: token-by-token text with a soft caret, three-dot typing indicator before the first token, and a "Stop generating" button.
- **Message actions**: copy, thumbs up/down, timestamps, an error bubble with "Retry", and "Not what you meant? Ask AI" on quick answers.
- **Empty state**: welcome message, starter question chips, and quick action buttons (open URL, email, call, WhatsApp, or send a preset message).
- **Composer**: auto-growing textarea, Enter to send, Shift+Enter for newline, character counter near the limit, disabled send while empty or streaming, input at least 16 px on mobile. Optional mic button using the browser's Web Speech API (hidden where unsupported) and optional read-aloud via `speechSynthesis`.
- **Proactive triggers**: show the teaser or auto-open after X seconds, at Y% scroll, on exit intent (desktop), or on specific pages, with frequency capping.
- **Error states**: if no first token arrives within a configurable timeout (default 20 seconds), show a friendly message with "Retry". Never spin indefinitely.
- **Motion**: default ~200 ms scale-and-fade open/close and gentle message fade-in, all disabled under `prefers-reduced-motion`.
- **Theming**: driven entirely by the design tokens from Module 5, applied as CSS custom properties on the shadow host, with CSS `part` attributes on key elements.
- **Mobile**: full-screen below 480 px (configurable), safe-area insets, body scroll lock, correct behavior with the virtual keyboard.
- **Accessibility**: `role="dialog"`, labeled controls, `aria-live="polite"` for new messages, focus management and return, Esc to close, WCAG AA contrast.
- **i18n and RTL**: strings passed from PHP; full RTL layout support.
- **Preview mode**: when mounted via `window.Chatlume.mountPreview( element, config )` or the signed preview parameter, the widget accepts config updates via origin-checked `postMessage`, stays pinned open, supports forced states, element highlighting, and click-to-edit hit-testing, and never logs conversations.
- **Cache-proof config**: the launcher's inline config carries a version hash; the chat app always receives fresh behavior config from `/session`. On publish, purge the page cache of popular caching plugins if active (WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, SiteGround Optimizer) through their public functions or hooks, each guarded with existence checks, and show an admin notice suggesting a CDN purge if one is detected. Settings never depend on cookies.
- **Optimization-plugin compatibility**: exclude widget scripts from JS combine, minify, and delay features in WP Rocket, LiteSpeed Cache, Autoptimize, Perfmatters, FlyingPress, and SiteGround Optimizer using each plugin's documented exclusion filters (verify names in their docs), guarded with existence checks.
- **Performance**: the launcher is a tiny vanilla TypeScript entry loaded with `defer`; the React chat app loads via dynamic `import()` on first click (or on idle if "preload" is enabled). No layout shift, no measurable impact on page load. No widget assets load where display rules hide it.
- **Embed modes**: floating launcher, `[chatlume height="600"]` shortcode, Gutenberg block `chatlume/chat` (inline mode), and a JS API: `window.Chatlume.open()`, `.close()`, `.toggle()`, `.send( text )`, `.mountPreview( element, config )`. When an inline chat is on a page, the floating launcher is hidden on that page.
- **Analytics events** (optional): push `chat_open`, `message_sent`, `lead_captured`, and `handoff` to `window.dataLayer` if present, and dispatch matching DOM CustomEvents. No external calls.

## Module 7: Data and storage
- `{prefix}chatlume_conversations`: id, session_id, user_id, visitor_hash, page_url, language, provider, model, lead_name, lead_email, rating, message_count, total_cost, is_test, meta (JSON), created_at, updated_at.
- `{prefix}chatlume_messages`: id, conversation_id, role, content, source (ai, quick_answer, cache, fallback_model, site_search), input_tokens, output_tokens, cost, latency_ms, feedback, sources (JSON), error_type, created_at.
- `{prefix}chatlume_knowledge`: id, source_type, source_id, title, url, language, chunk_index, content (FULLTEXT), embedding (LONGBLOB, nullable), embedding_model, embedding_dims, is_stale, updated_at.
- `{prefix}chatlume_quick_answers`: id, question, answer, keywords, language, enabled, hit_count, miss_count, created_at, updated_at.
- `{prefix}chatlume_rate_limits`: bucket_key, window_start, count (used when no persistent object cache is available; otherwise use the object cache). Never store rate limits as thousands of transients in wp_options.
- **Appearance**: the draft theme, published theme, last 10 published versions, and custom presets live in non-autoloaded options; only the resolved tokens live in the autoloaded `chatlume_widget_config`. Uploaded fonts live in `uploads/chatlume-fonts/`.
- Proper indexes on foreign keys, dates, language, and staleness. A daily cron purges data older than the retention period and expired rate-limit rows.
- Estimated cost: use pricing from OpenRouter's models list where available; otherwise the admin enters a price per 1M input/output tokens per model.
- Multisite: every table is per site; uninstall cleans every site when opted in.

## Module 8: Security and privacy
- **API keys**: encrypted at rest with libsodium using a key derived from WordPress salts, with a stored key fingerprint. If decryption fails (for example, salts rotated by a security plugin), show "Please re-enter your API key" on the Providers page and Dashboard instead of failing silently, and recommend `wp-config.php` constants. Any key can be overridden by a constant (e.g. `CHATLUME_OPENAI_API_KEY`), shown as "Defined in wp-config.php". Keys never reach the browser; only a masked version (last 4 characters) is returned.
- **Visitor IP**: `IpResolver` defaults to `REMOTE_ADDR`, validated with `filter_var( $ip, FILTER_VALIDATE_IP )`. Only when a trusted proxy is configured (Cloudflare or a custom IP list) does it read `CF-Connecting-IP` / `X-Forwarded-For`, and only from requests that come from those proxies. IPs are always hashed with a salt; raw IPs are never stored.
- **Public abuse protection**: signed session tokens, rate limiting per hashed IP and per session (default 20 messages per 10 minutes), global budget caps, max input length, honeypot field, same-origin checks, optional Cloudflare Turnstile. Handoff emails and lead submissions have their own limits (default 3 per session per hour).
- **Response cache safety**: never used for logged-in visitors; the cache key includes a hash of the fully resolved system prompt; skipped entirely when `{user_name}` is used.
- **Uploads**: admin only. Documents and fonts are validated by extension and MIME type with size limits and parsed safely; nothing is ever executed or publicly exposed. Font uploads accept woff2 only (MIME allowed for admins on the Customizer screen only). Images go through the Media Library (PNG, JPG, WebP); SVG uploads are allowed only if sanitized server-side with a Strauss-prefixed sanitizer.
- **Customizer**: preview tokens are signed, admin-only, and expire after 30 minutes; all preview `postMessage` traffic is origin-checked.
- **Webhooks**: signed with an HMAC-SHA256 header using a per-site secret, short timeout, one retry.
- **Prompt-injection hardening**: system prompt kept separate from user content, knowledge wrapped in delimiters, the model instructed not to reveal its instructions, client-supplied system/assistant messages rejected, model output never rendered as raw HTML.
- **Privacy**: optional consent notice, an option to disable conversation storage, WordPress personal-data exporter and eraser (by lead email), and suggested privacy policy text via `wp_add_privacy_policy_content()`.
- `uninstall.php` checks `WP_UNINSTALL_PLUGIN` and removes tables, options, transients, uploaded fonts, and cron events only if the admin opted in.

## Module 9: Extensibility
- **PHP filters**: `chatlume_register_providers`, `chatlume_system_prompt`, `chatlume_messages_before_send`, `chatlume_retrieved_context`, `chatlume_quick_answer_match`, `chatlume_response_text`, `chatlume_widget_config`, `chatlume_should_display_widget`, `chatlume_knowledge_sources`, `chatlume_webhook_payload`, `chatlume_rate_limit`, `chatlume_capability`.
- **PHP actions**: `chatlume_conversation_started`, `chatlume_message_received`, `chatlume_response_sent`, `chatlume_lead_captured`, `chatlume_handoff_requested`, `chatlume_budget_reached`.
- **Abilities API (WordPress 6.9+)**: register `chatlume/ask`, `chatlume/get-stats`, `chatlume/list-unanswered-questions`, and `chatlume/create-quick-answer`, each with a proper permission callback, so site owners can manage the bot from external AI agents. Verify the current registration function and hook in the official docs.
- **Admin JS registry**: `window.chatlume.admin.registerPage()`, `registerSettingsSection()`, `registerDashboardCard()` (see Module 4).

## Module 10: WordPress.org compliance and coding standards
This plugin is submitted to the WordPress.org Plugin Directory. Every rule below is mandatory.

### Directory guidelines
- **License**: GPLv2 or later in the main file header and readme. Every bundled library, font, icon, and image is GPL-compatible; list each with its license in a "Credits" section of the readme. Drop any asset whose license is unclear.
- **Human-readable code**: no obfuscation. Compiled JS ships with its source (see `npm run zip`), and the readme has a "Source code and build" section with the public repository URL and build commands.
- **No trialware**: every feature is fully functional with no locks, limits, or "pro" teasers. Any paid features ship later as a separate add-on using the extension points.
- **External services**: the readme's External services section covers every AI provider, Cloudflare Turnstile, admin-configured webhooks, and WordPress Connectors: what the service is, exactly what data is sent (visitor messages, page URL and title, knowledge excerpts, document text for embeddings), when it is sent, and links to each service's terms and privacy policy.
- **No tracking**: no telemetry, usage stats, or phone-home of any kind. Analytics events stay in the browser.
- **No remote code**: no JS, CSS, fonts, or images loaded from external servers; no custom updater; no `Update URI` header; never install or activate other plugins.
- **No public credits by default**: the "Powered by" link is off unless the admin opts in.
- **Respectful admin**: notices appear only on Chatlume screens (or are dismissible and shown once). The post-activation redirect to the setup wizard happens once, only for a single-plugin activation, and never on bulk activation, network activation, or WP-CLI.
- **Use core libraries**: never bundle a second copy of anything WordPress ships (React via `wp-element` in the admin, `wp.apiFetch`, `wp.i18n`, `wp.media`, `wp_mail()`, the HTTP API).
- **Versions and SVN**: bump the version in the main file header, the readme `Stable tag`, and `CHATLUME_VERSION` together on every release. SVN is for releases only.

### Sanitize early
- Every PHP file starts with `if ( ! defined( 'ABSPATH' ) ) { exit; }`.
- Read superglobals only when needed, always `wp_unslash()` first, then sanitize with the function matching the data type: `sanitize_text_field()`, `sanitize_textarea_field()`, `sanitize_email()`, `sanitize_key()`, `sanitize_hex_color()`, `esc_url_raw()`, `absint()`, `(float)`, `rest_sanitize_boolean()`, `sanitize_file_name()`, and `wp_kses_post()` (or a stricter `wp_kses()` allowlist) for rich text such as the welcome message. Sanitize arrays element by element. Never sanitize or store `$_POST` wholesale.
- Every REST route declares `args` with `type`, `required`, `enum`/`minimum`/`maximum`/`maxLength`, `sanitize_callback`, and `validate_callback`.

### Validate always
- Every route has an explicit `permission_callback`. Admin routes check `current_user_can()` against the `chatlume_capability` filter (default `manage_options`). Public routes use `'__return_true'` with a comment explaining that access is enforced by the signed session token, rate limits, and same-origin checks.
- Allowlist every enumerable value (provider IDs, positions, styles, roles); clamp every number; enforce maximum lengths. The PHP validators mirror the admin's Zod schemas.
- State-changing requests are protected by nonces (`wp_rest` for REST via `wp.apiFetch`; any non-REST handler uses `check_admin_referer()` / `check_ajax_referer()` plus a capability check).

### Escape late
- Escape every output at the moment it is printed: `esc_html()`, `esc_attr()`, `esc_url()`, `esc_textarea()`, `wp_kses()` / `wp_kses_post()`. Translated strings use `esc_html__()`, `esc_html_e()`, `esc_attr__()`, etc. Never echo a raw variable.
- Pass data to JavaScript only via `wp_add_inline_script()` with `wp_json_encode()`. Never print `<script>` or `<style>` tags directly; enqueue every script and style with `wp_enqueue_script()` / `wp_enqueue_style()` and a version, and load admin assets only on Chatlume screens (check the hook suffix).
- SSE frames contain only `wp_json_encode()` output. If a PHPCS sniff still flags it, add a single-line `phpcs:ignore` with a comment explaining why.
- Redirects use `wp_safe_redirect()` followed by `exit`.

### Database, files, and remote requests
- Every dynamic SQL value uses `$wpdb->prepare()` placeholders, with `%i` for identifiers. Prefer `$wpdb->insert()`, `update()`, and `delete()`. Never interpolate variables into SQL. Direct queries on custom tables carry a short comment explaining why core APIs cannot be used, and use `wp_cache_*` where results are reusable.
- Uploads go through `wp_handle_upload()` / `media_handle_upload()` with `wp_check_filetype_and_ext()`. Files are written only inside `wp_upload_dir()` using `WP_Filesystem`, never inside the plugin folder.
- All remote requests use the WordPress HTTP API (`wp_remote_request()` etc.), including streaming (see Module 2).
- Do not change PHP runtime settings (`ini_set()`, `set_time_limit()`, `error_reporting()`).
- Never use `eval()`, `create_function()`, `extract()`, `base64_decode()` on code, or shell execution functions. Use `gmdate()` / `wp_date()` instead of `date()`.

### Internationalization
- Every user-facing string uses translation functions with string literals only (no variables), the literal text domain `chatlume-ai-chatbot`, `_n()` for plurals, and translator comments for every placeholder used with `sprintf()`.
- Do not call `load_plugin_textdomain()`; WordPress loads translations for directory-hosted plugins automatically.

### Readme and assets
- `readme.txt` passes the official readme validator. Header fields (`Requires at least`, `Tested up to`, `Requires PHP`, `Stable tag`, `License`, `License URI`) match the main file header.
- At most 5 tags, a short description of 150 characters or fewer, no keyword stuffing, no affiliate links, and `Contributors` lists only real WordPress.org usernames.
- Sections: Description, Installation, FAQ, Screenshots (captions), Changelog, External services, Source code and build, Credits.
- Banner (772×250 and 1544×500), icon (128×128 and 256×256), and screenshots live in the SVN `/assets` folder, not in the plugin zip.

## Module 11: Quality and release
- PHPCS (WordPress-Extra and PHPCompatibilityWP for PHP 8.0+), wp-scripts lint-js and lint-style, and `tsc --noEmit`, all passing.
- PHPUnit tests for provider normalization and error mapping, HTTP streaming and abort handling, conversation state, encryption (including the unreadable-key case), IP resolver, rate limiter, stream limiter, cost guard, quick answer matching, response cache keys, chunker, n-gram scorer, two-stage retriever, document parser, site-search fallback, theme validation and clamping, token output, preview token signing, live-widget suppression in preview, upload validation, and activation redirect rules.
- Jest tests for the markdown sanitizer, stream reader, tab sync, trigger logic, palette generation, contrast checking and auto-fix, token resolution, theme schema migrations, undo/redo, the preview postMessage protocol (origin checks, batching), the control-to-state mapping, and key admin form validation.
- **Plugin Check** passes with zero errors; every remaining warning is fixed or justified in a code comment.
- i18n: `.pot` generated; admin JS translations loaded via `wp_set_script_translations()`.
- Tested with `WP_DEBUG` and `SCRIPT_DEBUG` on, on WordPress 6.5 and the latest release, with PHP 8.0 and the latest PHP version, on single-site and multisite.
- Verified with a block theme (Twenty Twenty-Five), a classic theme, a page builder, common caching plugins, and common optimization plugins with "delay JS" enabled, with zero CSS or JS conflicts.
- The release zip installs and activates cleanly on a fresh site, contains no development junk, and stays well under the directory's upload size limit.
- The submitted plugin is complete and fully working.

## Build phases
Status legend: ✅ done · 🚧 in progress · ⬜ not started. Approved deviations are listed under "Build log" below.

1. ✅ **Foundation** (done 2026-10-14): bootstrap, plugin identity and prefixes, Composer + Strauss setup, autoloader, multisite-aware activation with the redirect rules, schema and migrations, settings storage, encryption with fingerprint, wp-scripts build setup, and the admin shell with the design system foundation, sidebar navigation, and extension registry.
2. ✅ **Providers** (done 2026-10-14): interface, HTTP API transport and streamer, OpenAI-compatible base with presets, Anthropic and Gemini adapters, embeddings support, error normalization, live model lists with suggestions, test connection, primary/fallback, Providers page.
3. ✅ **Chat backend** (done 2026-10-14): session manager, conversation state, IP resolver, rate limiter, cost guard, quick answers, response cache, site-search fallback, no-key mode, prompt builder, non-streaming endpoint, logging.
4. ✅ **Widget** (done 2026-10-15): full widget UI, design token system, widget styles (floating, side panel, modal, inline), message styles, CSS parts, preview mode, streaming with stream limiter and abort handling, markdown sanitizer, source cards, quick actions, tab sync, mobile, accessibility, shortcode, block, JS API.
5. 🚧 **Admin experience** (in progress): setup wizard with provider guides, Dashboard, Chatbot, Playground, the Customizer with always-on live preview (presets, Simple/Advanced modes, colors, typography, layout, draft/publish, versions, real-site backdrop, context-aware states, click-to-edit, interactive preview, before/after, devices, Developer styling panel), Display & triggers, Conversations, Settings with Health check, core Command Palette commands.
6. ⬜ **Knowledge base**: indexer, document uploads, embeddings storage and re-embedding, two-stage retrieval with n-gram fallback, source links, WooCommerce sync, multilingual filtering, Knowledge page.
7. ⬜ **Engagement, integrations, and platform**: proactive triggers, voice, lead capture, handoff, webhooks, email notifications, analytics events, cache purging, optimization-plugin compatibility, WPML/Polylang, "Match my website", WordPress Font Library and theme.json integration, "Preview on my site", per-language content, WordPress Connectors bridge and core AI Client, Abilities registration.
8. ⬜ **Hardening and release**: privacy integrations, uninstall, full test suite, i18n, readme with all required sections, Plugin Check fixes, release zip.

### Build log

**Phase 1 – Foundation ✅ (2026-10-14)**
- [x] Bootstrap, identity, prefixes, PHP version and missing-autoloader notices
- [x] Composer PSR-4 autoloading; Strauss (pinned phar 0.27.4) prefixing into `vendor-prefixed/`
- [x] Multisite-aware activation, new-site install, per-site table drop on site deletion
- [x] One-time activation redirect (never on bulk, network, WP-CLI, AJAX, REST)
- [x] Five custom tables via dbDelta, separate FULLTEXT index with detection, DB version and settings schema migrations
- [x] Settings storage (`chatlume_settings`, not autoloaded) with schema-driven validation, sanitization, and clamping
- [x] libsodium encryption with key fingerprint and "key unreadable" handling
- [x] wp-scripts build (admin bundle), Tailwind 4 with `chatlume` prefix and no preflight, ESLint/Stylelint/tsc, Jest, PHPUnit, PHPCS
- [x] Admin shell: header, registry-driven sidebar, Home (system status), Settings (Data & privacy), light/dark mode per user, extension registry (`window.chatlume.admin`)
- [x] `uninstall.php` (pulled forward from Phase 8; removes data only when opted in)

Approved deviations and decisions:
- "Requires at least" is **WordPress 6.6** (the `react-jsx-runtime` script handle used by current wp-scripts builds does not exist in 6.5).
- Widget and block webpack configs are added in Phase 4 with their source; the sidebar lists only pages that exist.
- The activation redirect targets `admin.php?page=chatlume`; the wizard opens from the app in Phase 5.
- Settings classes live in `includes/Settings/`; the messages table has an extra `meta` JSON column.
- Admin unit tests run on Jest via `wp-scripts test-unit-jest` (wp-scripts 36 defaults to Vitest); React Router 7 is used because core still ships React 18.
- The release zip ships `vendor/autoload.php`, `vendor/composer/`, and `vendor-prefixed/` built with `composer install --no-dev`.
- Per-phase Plugin Check runs exclude dev-only files; the release zip is checked in Phase 8.

**Phase 2 – Providers ✅ (2026-10-14)**
- [x] Provider interface, normalized messages and `ChatResponse`
- [x] HTTP API transport and `HttpStreamer` (WordPress `requests-request.progress` hook; abort through a cURL progress callback attached via `http_api_curl`, or an exception on fsockopen)
- [x] OpenAI-compatible base with presets (OpenAI, OpenRouter, Groq, Mistral, DeepSeek, xAI, Together, Ollama, Custom endpoint)
- [x] Anthropic and Gemini adapters
- [x] Embeddings support and separate embeddings provider setting
- [x] Error normalization (`ProviderError`) with admin explanations and fix links; capped provider error log
- [x] Live model lists (12-hour cache, refresh, custom model ID) with recommendations
- [x] Test connection, primary/fallback, price per model
- [x] Providers page

Approved decisions:
- Recommendations use model **family patterns** in `data/model-suggestions.json`, matched against the live list; no exact model IDs are shipped.
- Live streaming is verified against a local llama.cpp server (OpenAI-compatible).
- The WordPress Connectors bridge stays in Phase 7; the provider error log UI is part of Settings in Phase 5.

Implementation decisions (for review):
- Aborting a stream by throwing from the cURL write callback does not work: Requests treats the failed write as an encoding error and silently re-sends the request. Streams therefore stop through a cURL transfer-progress callback attached to the WordPress HTTP API handle (`http_api_curl`), which also notices disconnects while the provider is silent. Verified live: abort in ~0.2–0.5 s instead of the full response time. No raw cURL requests are made.
- API keys have dedicated routes (`POST`/`DELETE /providers/{id}/key`) and their own encrypted option (`chatlume_api_keys`), separate from `chatlume_settings`, so settings can be returned or exported without keys. `CHATLUME_<ID>_API_KEY` constants override stored keys.
- Automatic pricing comes only from OpenRouter (the only provider documenting per-token prices in its model list); other providers use admin-entered prices per 1M tokens.
- OpenRouter app attribution headers (`HTTP-Referer`, `X-OpenRouter-Title`) are off by default because they share the site URL and name.
- Plugin Check 2.1 warns about direct AI provider integrations (`PluginCheck.CodeAnalysis.AIProvider.DirectIntegration`); these are justified in code comments (WordPress versions without the AI Client, streaming, providers core does not cover).

**Phase 3 – Chat backend ✅ (2026-10-14)**
- [x] Settings: chatbot behavior, quick answers, security limits, cost controls, response cache, storage and retention
- [x] IP resolver (trusted proxies, hashed IPs) and signed session manager
- [x] Rate limiter (object cache or `chatlume_rate_limits` table)
- [x] Conversation state and repositories (database, or a 30-minute transient when storage is off)
- [x] Prompt builder with presets and variables
- [x] Quick answers: matcher and admin REST CRUD
- [x] Response cache
- [x] Cost guard: message cap, daily/monthly budgets, cap behavior, 80%/100% email alerts
- [x] Site-search fallback and no-key mode
- [x] Chat pipeline, public REST routes (`/session`, `/chat`, `/feedback`), logging, retention cron

Approved decisions:
- Quick answers get backend REST CRUD now; their UI comes with the Knowledge page (Phase 6).
- Cloudflare Turnstile is built in Phase 7 with the other integrations.
- Budget alert emails (80% and 100%) are built in Phase 3 as part of cost control.
- The visitor language is the page language sent by the widget (validated on the server); the prompt still asks the model to reply in the visitor’s language, and cached answers are keyed by page language.

Implementation decisions (for review):
- Extra reply sources beyond the spec list: `guardrail` (blocked words) and `offline` (budget used up with the offline behavior), so the log shows exactly why each reply was given.
- Chat settings live in their own sections (`chatbot`, `quick_answers`, `security`, `costs`, `cache`) plus `privacy.store_conversations` and `privacy.retention_days`. Empty text settings mean "use the built-in default", so defaults stay translated and preset-dependent.
- Session tokens are hex-encoded HMAC-signed payloads (no base64, to avoid obfuscation flags); they expire after `security.session_hours` (default 24).
- The per-IP rate limit uses the same numbers as the per-session limit (spec default 20 per 10 minutes). Visitors behind one shared IP (offices, schools) share that budget; the `chatlume_rate_limit` filter can raise it per bucket.
- Response-cache keys include the answering provider and model, so switching models never serves another model’s answer.
- `POST /feedback` and `POST /chat` set `Cache-Control: no-store`. Public routes require a matching Origin or Referer (`chatlume_allowed_origins` filter for future external embedding).
- Unstored conversations (storage off) use IDs starting with `t_` and live in a transient bound to the session for 30 idle minutes.

**Phase 4 – Widget ✅ (2026-10-15)**
- [x] Design token system: versioned theme schema with PHP validation and clamping; resolved light/dark tokens stored in the autoloaded `chatlume_widget_config` (about 3 KB) with a version hash
- [x] Launcher: vanilla TypeScript, loaded with `defer`, Shadow DOM `<chatlume-widget>`, tokens applied on first paint, 12 built-in icons, circle/square/pill shapes, unread dot, badge, pulse or bounce, dismissible teaser
- [x] Chat app (Preact through `preact/compat`), loaded with `import()` on first open, or when idle if preload is on
- [x] Widget styles: floating, side panel, centered modal, inline. Message styles: bubbles, flat, outlined. Backgrounds: solid, pattern, gradient, glass
- [x] CSS parts (launcher, teaser, window, header, message-bot, message-user, composer, source-card, starters); site CSS can override `--chatlume-*` tokens
- [x] Streaming: `POST /chat/stream` (SSE events start, token, sources, done, error), stream limiter (answers without streaming when full), maximum duration below `max_execution_time`, heartbeats, stop requests (`POST /chat/stop`), buffering detection with automatic fallback to `/chat`, first-token timeout
- [x] Allowlist Markdown renderer (model output is never parsed as HTML), code blocks with copy, safe links
- [x] Source cards, typing indicator, Stop, Retry, copy, thumbs up/down, "Not what you meant? Ask AI", timestamps (hover, always, never)
- [x] Empty state: welcome message, starter questions, quick actions (URL, email, phone, WhatsApp, preset message)
- [x] Tab sync (BroadcastChannel or `storage` events) with one streaming tab at a time; localStorage restores the visible conversation
- [x] Mobile full screen (configurable width), safe areas, body scroll lock, visual-viewport height, 16 px input
- [x] Accessibility: dialog role, labelled controls, polite announcement of finished replies, focus management and return, Esc, focus trap in modal and full screen
- [x] RTL (follows the page direction) and color modes (light, dark, auto)
- [x] Preview mode: `window.Chatlume.mountPreview()`, origin-checked `postMessage` batched per animation frame, forced states, highlight, click to edit
- [x] `[chatlume height="600"]` shortcode and `chatlume/chat` block (inline mode; the floating launcher is hidden on that page)
- [x] JS API: `window.Chatlume.open()`, `.close()`, `.toggle()`, `.send( text )`, `.mountPreview( element, config )`
- [x] Behavior settings always fresh from `/session`; pages served from an old cache receive the current theme

Approved decisions:
- The floating widget shows site-wide by default (`display.enabled`); page and device display rules come with "Display & triggers" in Phase 5.

Implementation decisions (for review):
- Stopping a stream: many servers (including Local on Windows) never tell PHP that the visitor disconnected, so aborting the request alone did not stop the AI. The widget therefore sends `POST /chat/stop` ("Stop generating"), or a beacon when the page closes. The running stream checks for it every 0.5 seconds and aborts the upstream request. Only the session that started a stream can stop it. Verified live: the upstream request closed about 0.3 seconds after Stop or after leaving the page. Stopped replies are saved with the conversation.
- The SSE `start` event (conversation ID and stream ID) is an addition to the token/sources/done/error events.
- When every stream slot is taken, `/chat/stream` answers without streaming in the same response, so the widget needs no second request.
- New setting `chatbot.quick_actions` (up to 4); links are built and validated on the server.
- Widget strings are printed with the page; behavior settings (bot name, welcome, starters, quick actions) come from `/session` and are kept in localStorage for the next first paint.
- Sizes: launcher 6.7 KB gzipped; chat app 22.5 KB gzipped (Preact included), loaded on first open.
- The teaser shows when its text is set and can be dismissed. Timed, scroll, and exit-intent triggers come in Phase 7, as do the mic, read-aloud, and "Email transcript" (with handoff).
- Sample content for the preview states is supplied by the Customizer (Phase 5), so it is translated there.
- The admin app's strict settings schema now includes the new `display` and `streaming` sections (without them every settings save failed with "Unrecognized key"). A Jest test compares its sections with `SettingsSchema::definition()`, so the two can no longer drift apart.

**Phase 5 – Admin experience 🚧 (in progress)**

Approved decisions:
- Delivered module by module, each committed after review, in this order: Settings and Health check, Chatbot, Conversations, Dashboard, Playground, Display, Customizer, setup wizard, Command Palette commands.
- The Display & triggers page builds the display rules and business hours now; proactive triggers (time on page, scroll depth, exit intent, frequency caps) come in Phase 7 with the rest of engagement.
- The bundled open-license fonts (Inter, DM Sans, Manrope, Plus Jakarta Sans, Lora, Noto Sans Bengali, Noto Sans Arabic) and woff2 uploads are part of the Customizer in Phase 5.

Module 5.1 – Settings and Health check ✅ (2026-10-15)
- [x] General tab: rate limits, chat session length, trusted proxy (none, Cloudflare, custom IP list with inline validation), streaming toggle and limits, response cache with "Clear cached answers", conversation storage and retention, delete data on uninstall, debug logging
- [x] Import and export of settings as JSON (API keys and the widget appearance are never included; `setup` is never imported)
- [x] Health check tab: PHP version, database tables, API key encryption, HTTP transport, REST API reachability (loopback), WP-Cron, FULLTEXT, request time limit, object cache, caching and optimization plugins, WordPress AI Client, each with a plain-language fix; live streaming test run from the browser
- [x] Logs tab: provider error log with the same explanations and fix links as the Providers page, and the debug log
- [x] REST: `GET /health`, `POST /health/stream-test`, `DELETE /health/errors`, `DELETE /health/debug-log`, `POST /cache/clear`
- [x] Settings tabs are kept in the address (`#/settings?tab=health`) so other pages can link to them

Implementation decisions (for review):
- Debug logging records only summaries (reply source, provider and model, tokens, cost, latency, error codes, whether it streamed or was stopped) in one capped, non-autoloaded option (latest 100). Message text, visitor details, IP addresses, and keys are never recorded, and no log files are written.
- "Clear cached answers" bumps a cache generation number that is part of every cache key, so all earlier answers stop matching at once and expire on their own.
- The streaming test sends six events a quarter second apart from an admin-only route; the browser judges the result by when the events arrive, because only the browser can see buffering by proxies and CDNs.
- Recommended PHP version is 8.2 or newer (the oldest PHP line still receiving security fixes on 2026-10-15); 8.0 and 8.1 still work and get a "recommended" notice.

Module 5.2 – Chatbot page ✅ (2026-10-15)
- [x] Personality: bot name, starting point (Customer support, Sales assistant, FAQ bot, Blank), system prompt editor with variable buttons inserted at the cursor, a character counter, and "Edit the starting instructions"
- [x] First impression: welcome message, four starter question slots, up to four quick action buttons with per-type validation
- [x] How it answers: creativity (temperature), longest answer, messages remembered, longest visitor message, reply language (visitor's or fixed)
- [x] Guardrails: site topics only, refusal message, blocked words
- [x] Quick answers: on/off, match strictness, show as starters
- [x] Cost controls with today's and this month's usage: message cap per conversation, budget unit, daily and monthly budgets, behavior when used up, alert email
- [x] Messages: offline, error, and site-search introduction
- [x] Preview drawer: the real widget in preview mode, updated live from unsaved changes, with sample replies (no provider calls)
- [x] REST: `GET /chatbot` (preset defaults, prompt variables, budget usage)

Implementation decisions (for review):
- Empty text fields keep using the built-in, translated defaults of the chosen starting point; the defaults are shown in grey so the admin sees exactly what visitors get.
- The admin screen loads the widget script without page configuration, so it shows no floating button there and only provides `window.Chatlume.mountPreview()` for previews.

Module 5.3 – Conversations ✅ (2026-10-15)
- [x] Searchable, filterable list (search in messages, lead names, and emails; date range in the site's timezone; rating; answer type; has lead; language), 25 per page; filters, page, and the open transcript are kept in the address
- [x] Transcript panel: page, language, AI model, estimated cost, lead, visitor type, and every message with its answer type, tokens, cost, response time, feedback, stop or provider error, and source links
- [x] Delete one conversation or many at once (with confirmation)
- [x] Export CSV (one row per message, UTF-8 with BOM) or JSON, using the current filters, up to the newest 2,000 conversations
- [x] REST: `GET/DELETE /conversations`, `GET /conversations/export`, `GET/DELETE /conversations/{id}`

Implementation decisions (for review):
- Test conversations (`is_test`) are never listed or exported; session IDs and visitor hashes are never sent to the browser.
- CSV cells that start with `=`, `+`, `-`, `@`, a tab, or a carriage return get a leading apostrophe, so visitor messages cannot run as spreadsheet formulas.
- Empty date filters use fixed boundary dates, because strict SQL modes reject comparing a DATETIME column with an empty string.
- Filter changes build on the address the page last requested, so quick successive changes (or a delayed search) never undo each other.

Module 5.4 – Dashboard ✅ (2026-10-15)
- [x] Today / 7 days / 30 days: conversations, questions, tokens, estimated cost, and money saved by quick answers and the cache
- [x] Questions per day chart with tooltips and an accessible data table
- [x] Connection: primary (and fallback) provider with status, no-key explanation, daily and monthly budget usage, and a link to the Health check when something needs attention
- [x] "Questions your bot couldn't answer" (site-search fallback, rated not helpful, no knowledge match, "Ask AI" overruling a quick answer), grouped by question, with "Create quick answer"
- [x] Recent conversations linking to their transcripts
- [x] Setup checklist (hidden once complete); steps link only to pages that exist
- [x] Cards registered by add-ons (`window.chatlume.admin.registerDashboardCard()`)
- [x] REST: `GET /stats?days=1|7|30`

Implementation decisions (for review):
- "Saved" is estimated as the number of quick answers and cached answers multiplied by the average cost of an AI answer in the same period (or over all time when the period had none).
- The chart is a small built-in SVG instead of a charting library: one bar series does not justify a ~100 KB dependency, and it uses the admin's light and dark theme colors directly.
- Days follow the site's timezone; the Home page became the Dashboard (installation details live in the Health check).

Module 5.5 – Playground ("Test your bot") ✅ (2026-10-15)
- [x] Chat with the real bot inside the admin; replies render with the same allowlist Markdown as the widget
- [x] "Why this answer" panel per reply: plain-language explanation, source, provider and model, tokens, estimated cost, time, matched quick answer with its score, response cache state, error type, knowledge passages with scores, and the final prompt sent to the AI (never keys)
- [x] "Ask AI instead" on quick answers, "Save as quick answer", "Improve this answer" (edit the reply into a quick answer), and "New conversation"
- [x] The quick answer sheet is shared with the Dashboard
- [x] REST: `POST /playground/chat` (admin only)

Implementation decisions (for review):
- Playground chats run as a logged-out visitor (no user ID or name), so caching and personalization behave exactly as visitors experience them.
- They are stored as test conversations (hidden from Conversations and statistics) and skip visitor rate limits; AI replies still count toward the budget because they cost real money.
- The pipeline builds its trace only when the admin-only route asks for it; visitor routes never return it.

Module 5.6 – Display & business hours ✅ (2026-10-15)
- [x] Chat button on/off and "Open instantly" (preload)
- [x] Pages: all, only on…, or everywhere except…, matching chosen pages and posts (title search), content types (single items and archives), and page addresses with `*` wildcards
- [x] Devices (computers and phones, computers only, phones only), visitors (everyone, logged out, logged in) and roles
- [x] Business hours per day in the site timezone, including hours past midnight and all-day; "Copy Monday" shortcuts; "Open now / Closed now"; outside hours either the offline message (input disabled, header shows "Away", contact buttons still work) or a hidden chat button; the offline message is editable here
- [x] Copy-ready shortcode and block instructions
- [x] REST: `GET /display` (post types, roles, chosen page titles, timezone, open now)

Implementation decisions (for review, chosen 2026-10-15):
- One rule list with a mode (only on / everywhere except) instead of separate include and exclude lists.
- Page, address, and visitor rules are decided on the server; device and business-hours rules in the browser, because a cached page would otherwise keep the state from when it was cached. The chat endpoints also enforce business hours, so the closed bot never calls the AI or serves quick answers.
- Outside hours the admin chooses between the offline message (default) and hiding the button; chats placed inside pages always show the offline message.
- Phones are screens narrower than 768 pixels. "/shop/*" also matches "/shop"; addresses are compared without case, query string, or trailing slash, relative to the site address.
- Proactive triggers (time on page, scroll depth, exit intent, frequency caps) remain in Phase 7, as agreed.

Module 5.7 – Customizer, delivered in three parts (decided 2026-10-15): 5.7a core editor, 5.7b typography and fonts, 5.7c site preview and power tools.

Module 5.7a – Customizer core editor ✅ (2026-10-15)
- [x] Full-width editor: settings panel and an always-visible live preview (sticky; "Show preview" overlay below 1024 px)
- [x] Simple mode (brand color, avatar, logo, font, position, presets) and Advanced mode (every option), remembered per browser
- [x] Draft autosave, "Unpublished changes" badge, Publish, Discard changes, Reset to defaults, reset per section, last 10 published versions with restore
- [x] Undo and redo (buttons, Ctrl/Cmd+Z, Shift+Ctrl/Cmd+Z, Ctrl+Y); a drag of a slider or color picker is one step
- [x] Colors: one brand color generates complete light and dark palettes (OKLCH); every token editable per mode in Advanced; color mode light, dark, or follow the visitor; header solid, gradient (angle), or minimal; hex input, system picker, eyedropper where supported, recent colors
- [x] Contrast checker for every text and background pair (WCAG AA; 3:1 for the launcher icon) with "Fix" per pair and "Fix all automatically"
- [x] 10 presets with live thumbnails (Minimal, Midnight, Glass, Corporate, Soft, Playful, Mono, Ocean, Sunset, Forest) and "Keep my brand color"; every preset passes AA in light and dark
- [x] Brand (avatar as initials, Media Library image, or illustration; header logo, title, subtitle, status; "Powered by" off by default), layout (style, position, size, offsets for desktop and phones, full screen on phones, z-index), shape (corner presets and per-element radii, shadow, spacing), messages, background, composer, launcher (12 icons, avatar or image, shape, label, size, colors, attention effect, teaser), motion
- [x] Preview tools: state switcher (conversation with Markdown, code block, and source cards; empty; closed launcher; writing; typing; error; offline), light/dark override, right-to-left, "Show launcher"
- [x] REST: `GET /appearance`, `GET/POST/DELETE /appearance/draft`, `POST /appearance/publish`, `GET /appearance/versions`, `POST /appearance/versions/{id}/restore`

Implementation decisions (for review):
- The admin resolves the draft into widget tokens itself (a TypeScript port of TokenResolver), so every change reaches the preview without a server round trip; a shared fixture test keeps both resolvers identical.
- Built-in presets live in the admin as a brand color plus style choices; their palettes are generated and tested for AA. Saved custom presets (5.7c) will be stored on the server.
- Restoring a version loads it into the draft; it goes live only after Publish.
- A draft equal to the published theme is deleted, so "Unpublished changes" disappears when edits are undone.

Module 5.7b – Customizer typography and fonts ✅ (2026-10-15, awaiting review)
- [x] Font sources: same as the site, system stacks, 7 bundled open-license variable fonts (Inter, DM Sans, Manrope, Plus Jakarta Sans, Lora, Noto Sans Bengali, Noto Sans Arabic), the active theme's theme.json fonts, the WordPress Font Library, and uploaded woff2 files
- [x] Separate text and heading fonts; every stack ends with fallbacks for Bengali, Arabic, and CJK
- [x] "Script support" note when the chosen font has no letters for the site's language; a deleted font falls back to the system font with a note
- [x] Shadow DOM font loading: faces registered at document level with the FontFace API (`font-display: swap`), only for fonts in use; the browser downloads only the subsets a page needs
- [x] woff2 uploads (2 MB, at most 20) checked by extension, size, and the file's own signature, stored with WP_Filesystem in uploads/chatlume-fonts/, deletable; removed on uninstall when the admin opted in
- [x] REST: `POST /appearance/fonts`, `DELETE /appearance/fonts/{id}`; `GET /appearance` includes the font catalog
- [x] readme Credits list the bundled fonts and their licenses; each font's OFL text ships next to its files

Implementation decisions (for review):
- Bundled fonts come from the Fontsource packages (variable weight axis, normal style, every subset with its unicode-range); about 0.95 MB on disk, none of it loaded until the chat is opened with that font.
- Bundled and uploaded faces use namespaced family names ("Chatlume Inter", "Chatlume Custom …") so they never clash with fonts the site already loads.
- Uploads use Chatlume's own upload route instead of allowing woff2 in the Media Library, so no site-wide MIME change is needed.
- The stored widget configuration is rebuilt automatically after a plugin update or a site move, because font URLs depend on both.
- Phones keep the message field at 16 px or larger, whatever the base size (no iOS zoom).

## Post-launch roadmap (design for these, do not build now)
Keep the architecture ready for these without rewrites:
- AI Actions via provider tool calling, using the Abilities API as the tool registry (lead qualification, quote requests, bookings).
- Live human takeover inbox in wp-admin, with Slack notifications.
- WooCommerce in-chat product cards, add to cart, and order status lookup.
- Conversational form builder with conditional logic.
- Widget embedding on external, non-WordPress sites with an allowed-origins list.
- Omnichannel: WhatsApp, Telegram, Messenger.
- White-label mode for agencies.
- URL and sitemap crawler for external knowledge sources.
- AI-generated weekly insights email.

## Output rules
- Every file must be complete and runnable. No TODOs or placeholders.
- State any assumption explicitly.
- Prefer clarity over cleverness; comment non-obvious logic.
- If unsure of a provider's or WordPress core's current API, check the official documentation rather than guessing.

## Definition of done
- A fresh install goes from activation to a working chat in under 3 minutes using the wizard; with "Skip AI for now", the bot answers from quick answers and site search.
- On WordPress 7.0+, a provider connected in Settings > Connectors works in Chatlume without re-entering the key. On WordPress 6.5, everything works without core AI features and with no errors.
- Switching the primary provider works immediately, and published changes appear on the frontend without clearing browser cache or cookies. Drafts are never visible to visitors.
- Streaming works for every provider through the WordPress HTTP API and falls back gracefully where the server buffers. Ten simultaneous chats on a host with 2 PHP workers do not make the site unresponsive. Closing the tab mid-stream stops the upstream request within 2 seconds.
- With an invalid key, an out-of-credits account, or rotated salts, the visitor sees a friendly response and the admin sees the exact cause and fix.
- Quick answers and cached answers are served with zero API calls, and the dashboard shows the savings.
- Answers that use site content show correct source links. The Playground shows exactly why each answer was given.
- A non-technical user matches the widget to their brand in under 2 minutes using "Match my website" or a single brand color.
- Every Customizer change appears in the live preview in under 100 ms with no reload; the preview widget stays open and keeps its conversation through every change; focusing a control shows the matching state, and clicking a preview element opens its control; the real-site backdrop falls back cleanly where framing is blocked; test chats never appear in Conversations or stats.
- Every built-in preset passes WCAG AA in light and dark mode. The launcher shows no flash of default styles. Custom and bundled fonts render inside Shadow DOM in Chrome, Safari, and Firefox. The message input never triggers iOS zoom.
- No API key ever appears in page source, REST responses, or JS.
- The widget looks correct and isolated on multiple themes and a page builder, in light and dark mode, on desktop and mobile, and in RTL, and its lazy chunk loads with WP Rocket and LiteSpeed "delay JS" enabled.
- Verified in English, Bengali, Arabic (RTL), and Chinese.
- No PHP notices with `WP_DEBUG`, no console errors, all linters and tests pass, Plugin Check passes with zero errors, and the readme passes the official validator.