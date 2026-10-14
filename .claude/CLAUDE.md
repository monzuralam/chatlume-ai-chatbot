# Chatlume – AI Chatbot (WordPress plugin)

## Source of truth
- The full product and technical spec is `docs/SPEC.md`. Before starting any phase,
  read the modules that phase touches. If the spec is ambiguous, ask before guessing.
- Build strictly phase by phase ("Build phases" in the spec). Never start the next
  phase until I confirm.

## Identity (never deviate)
- Name: Chatlume – AI Chatbot. Slug, folder, and text domain: `chatlume-ai-chatbot`.
  Main file: `chatlume-ai-chatbot.php`.
- Namespace `Chatlume`, vendor prefix `Chatlume\Vendor\`, PHP prefix `chatlume_`,
  constants `CHATLUME_*`, REST `chatlume/v1`, tables `{prefix}chatlume_*`,
  handles `chatlume-*`, CSS variables `--chatlume-*`, element `<chatlume-widget>`.

## Golden rules (WordPress.org review)
- Every PHP file starts with the ABSPATH guard.
- Sanitize early (`wp_unslash()` + type-specific sanitize), validate always (REST
  `args` and `permission_callback` on every route, nonces, capability checks),
  escape late (`esc_html`, `esc_attr`, `esc_url`, `wp_kses` at output).
- `$wpdb->prepare()` for every dynamic SQL value. WordPress HTTP API for all remote
  requests. `WP_Filesystem` + `wp_upload_dir()` for files.
- Enqueue all scripts and styles; pass data with `wp_add_inline_script()` +
  `wp_json_encode()`. No external CDNs, no tracking, no remote code.
- Translation functions use string literals and the literal text domain
  `chatlume-ai-chatbot`.
- API keys never reach the browser, logs, or REST responses.

## Git and authorship
- Never add AI attribution of any kind: no `Co-Authored-By` trailers, no
  "Generated with" lines, no session links, and no mention of Claude, Anthropic, or
  AI tools in commit messages, PR descriptions, code comments, file headers,
  readme.txt, changelog entries, or documentation.
- All commits are authored only by the repository owner, using the git identity
  already configured. Never change `user.name` or `user.email`, and never use
  `--author`.
- Never commit on your own. When you finish a module or a whole phase, stop and
  ask me: "<Module or Phase name> is complete. Shall I commit it?" Show the list
  of files that would be committed and a proposed commit message. Commit only
  after I answer yes. If I say no, leave the changes uncommitted.
- Never push, force-push, amend, rebase, or rewrite history unless I explicitly ask.
- Commit messages follow Conventional Commits (`feat:`, `fix:`, `refactor:`,
  `test:`, `docs:`, `build:`, `chore:`): imperative mood, subject line of 72
  characters or fewer, and an optional body explaining why.
- Before committing, run `git status` and `git diff --staged` and make sure no
  secrets, API keys, `.env` files, `build/`, `vendor/`, `node_modules/`, logs, or
  zip files are staged.

## Local environment (Local by WP Engine, no Docker)
- Site: http://chatlume.local. This folder lives at
  `wp-content/plugins/chatlume-ai-chatbot`; the WordPress root is `../../..`.
- WP-CLI is available as `wp` because sessions start from Local's site shell.
  Examples: `wp plugin activate chatlume-ai-chatbot`,
  `wp plugin check chatlume-ai-chatbot`, `wp option get siteurl`.
- PHP errors are logged to `../../debug.log` (wp-content/debug.log). Check it after
  every change and fix anything coming from Chatlume.
- Emails sent by the plugin appear in Local's built-in mail catcher.
- Never store passwords or API keys in this file or anywhere in the repo.

## Commands
- `npm run start` / `npm run build` / `npm run lint` / `npm run test`
- `composer install` (runs Strauss) / `composer test` / `npm run lint:php`
- `npm run zip` builds the release zip.

## Definition of done for every phase
1. Code complete: no TODOs, placeholders, or stubs.
2. Build, lint (JS and PHP), and all tests pass.
3. The plugin activates on the local site, `debug.log` has no notices or warnings
   from Chatlume, the browser console has no errors, and
   `wp plugin check chatlume-ai-chatbot` reports no errors.
4. Report the files created or changed, manual test steps, and any assumptions.
5. Ask me whether to commit (see "Git and authorship").
6. After committing (or if I say no), show the result and stop. Never start the
   next phase until I tell you to.

## Never
- Never include `.claude/`, `docs/`, `node_modules/`, or tests in the release zip.
- Never hardcode AI model names or bundle third-party logos.
- Never change PHP runtime settings (`ini_set()`, `set_time_limit()`).
- Never write log or debug files inside this plugin folder.