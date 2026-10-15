=== Chatlume – AI Chatbot ===
Tags: chatbot, ai, chat, live chat, customer support
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add a fast, brandable AI chatbot to your site using the AI provider of your choice, with streaming replies, quick answers, and cost controls.

== Description ==

Chatlume adds a smart AI chatbot to your WordPress site. Connect the AI provider you prefer, match the chat widget to your brand, and keep costs under control with quick answers, caching, rate limits, and budget caps.

This is an early development version. It includes the chat widget, the chat backend, AI provider connections, and the Chatlume admin app. The appearance Customizer and setup wizard are added in upcoming releases.

* A fast, accessible chat widget with replies that appear word by word, a Stop button, source links, and copy and feedback buttons. It loads its chat window only when a visitor opens it.
* Show the chat as a floating button on every page, or embed it in a page with the `[chatlume]` shortcode or the Chatlume Chat block.
* Works on phones (full screen), in right-to-left languages, and in light or dark mode.
* Connect OpenAI, Anthropic, Google Gemini, OpenRouter, Groq, Mistral AI, DeepSeek, xAI, Together AI, a local Ollama server, or any OpenAI-compatible endpoint.
* Live model lists straight from each provider, so new models appear without a plugin update.
* A fallback provider takes over automatically if the primary provider fails.
* Clear explanations and fix links when a provider rejects a request.
* Secure, encrypted storage for API keys, derived from your site's security keys. Keys can also be defined in wp-config.php.
* A modern admin app with light and dark mode.
* Multisite support: every site in a network keeps its own settings and data.
* No ads, upsell banners, tracking, or remote code.

== Installation ==

1. Upload the `chatlume-ai-chatbot` folder to `/wp-content/plugins/`, or install the plugin from the Plugins screen.
2. Activate the plugin through the Plugins screen.
3. Open **Chatlume > Providers**, add an API key for the provider you want to use, and test the connection.

To keep a key out of the database, define it in wp-config.php instead, for example `define( 'CHATLUME_OPENAI_API_KEY', 'your-key' );`. The constant name is shown on each provider's settings panel.

== Frequently Asked Questions ==

= Does Chatlume send data to external services? =

Only to the AI provider you choose, and only when Chatlume needs it to answer or when you test a connection or load the model list. See the External services section below for exactly what is sent.

= Can I use Chatlume without sending data to a cloud AI service? =

Yes. Use Ollama or another OpenAI-compatible server running on your own infrastructure. Without any provider, the bot can still answer with quick answers and site search.

= Where are API keys stored? =

Encrypted in your site's database using a key derived from your WordPress security keys (salts), or in wp-config.php if you define them there. Keys are never shown again in full and never sent to visitors' browsers.

= Can I stop Chatlume from storing conversations? =

Yes. Turn off conversation storage and conversations are kept only briefly (30 minutes of inactivity) to keep the chat flowing; nothing is written to the database. When storage is on, conversations are deleted automatically after the retention period (90 days by default). Visitor IP addresses are never stored; only a salted hash is used for rate limiting.

= How do I put the chat inside a page instead of a floating button? =

Add the **Chatlume Chat** block, or the shortcode `[chatlume height="600"]` (height in pixels, 320 to 1200). On pages with an embedded chat, the floating button is hidden. Developers can also open the chat from their own buttons with `window.Chatlume.open()`, `window.Chatlume.close()`, `window.Chatlume.toggle()`, and `window.Chatlume.send( 'Hello' )`.

= Can I restyle the widget with my own CSS? =

Chatlume stores no custom CSS, but the widget reads `--chatlume-*` CSS custom properties, so you can override them in Appearance > Customize > Additional CSS (or the Site Editor's Styles), for example `chatlume-widget { --chatlume-primary: #0a7d33; }`. Key elements can also be styled with `chatlume-widget::part(launcher)`, `::part(window)`, `::part(header)`, `::part(message-bot)`, `::part(message-user)`, `::part(composer)`, and `::part(source-card)`.

= What happens to my data if I delete the plugin? =

Nothing is removed unless you enable **Delete all data on uninstall** in Chatlume > Settings. With that option on, deleting the plugin removes Chatlume's database tables, settings, and stored API keys.

== Screenshots ==

1. The Chatlume admin home screen with system status.
2. Providers: routing, provider cards, and the configuration panel with a live connection test.

== Changelog ==

= 0.1.0 =
* Initial development release: plugin foundation, database tables, settings storage, encrypted key storage, and the admin app shell.
* AI providers: OpenAI-compatible services, Anthropic, and Google Gemini, with streaming, live model lists, connection tests, primary and fallback providers, a separate embeddings provider, and plain-language error explanations.
* Chat backend: visitor sessions, quick answers, response cache, budgets with email alerts, rate limits, guardrails, site-search fallback that works without an API key, and conversation logging with automatic retention cleanup.
* Chat widget: floating, side panel, modal, and inline styles; streaming replies with Stop; safe Markdown; source links; quick answers with "Ask AI"; starter questions and quick action buttons; feedback; mobile full screen; RTL and dark mode; tab sync; `[chatlume]` shortcode, Chatlume Chat block, and a JavaScript API.
* Settings: rate limits, trusted proxy, streaming limits, response cache with one-click clearing, privacy and retention, optional debug logging, settings import and export, a Health check with plain-language fixes and a live streaming test, and a provider error log.
* Chatbot page: starting points (support, sales, FAQ, blank), instructions with variables, welcome message, starter questions, quick action buttons, answer settings, language, guardrails, quick answer matching, cost controls with current usage, fallback messages, and a live preview.
* Conversations: search and filter by date, rating, answer type, lead, and language; full transcripts with sources, tokens, and estimated cost per message; single and bulk delete; CSV and JSON export.

== External services ==

Chatlume connects to the AI provider(s) that you, the site administrator, set up under Chatlume > Providers. Nothing is sent to any provider until you add one, and only the providers you configure are ever contacted.

**What is sent and when**

* When you load or refresh a provider's model list: your API key (as an authentication header) to that provider's model list endpoint.
* When you test a connection: your API key and a short test prompt ("Reply with the single word OK.").
* When the chatbot answers a visitor: the visitor's message, the recent conversation, your system prompt, the current page's title and URL, the visitor's display name if your prompt uses {user_name}, and matching excerpts from your site's content, sent to the primary provider (and to the fallback provider if the primary fails). Quick answers, cached answers, and site-search results are served without contacting any provider.
* When building the knowledge base (in later versions): the text of the posts, pages, products, and documents you choose to index, sent to the embeddings provider.
* OpenRouter only, and only if you turn on "Send app attribution": your site's address and name.

Chatlume does not send any data to its authors, and it uses no analytics or tracking.

**Services**

* OpenAI API (api.openai.com). [Services Agreement](https://openai.com/policies/services-agreement/), [Privacy Policy](https://openai.com/policies/privacy-policy/).
* Anthropic API (api.anthropic.com). [Commercial Terms](https://www.anthropic.com/legal/commercial-terms), [Privacy Policy](https://www.anthropic.com/legal/privacy).
* Google Gemini API (generativelanguage.googleapis.com). [Gemini API Additional Terms](https://ai.google.dev/gemini-api/terms), [Google APIs Terms](https://developers.google.com/terms), [Privacy Policy](https://policies.google.com/privacy).
* OpenRouter (openrouter.ai). [Terms](https://openrouter.ai/terms), [Privacy Policy](https://openrouter.ai/privacy).
* Groq (api.groq.com). [Services Agreement](https://console.groq.com/docs/legal/services-agreement), [Privacy Policy](https://groq.com/privacy-policy).
* Mistral AI (api.mistral.ai). [Commercial Terms](https://legal.mistral.ai/terms/commercial-terms-of-service), [Privacy Policy](https://legal.mistral.ai/terms/privacy-policy).
* DeepSeek (api.deepseek.com). [Open Platform Terms](https://cdn.deepseek.com/policies/en-US/deepseek-open-platform-terms-of-service.html), [Privacy Policy](https://cdn.deepseek.com/policies/en-US/deepseek-privacy-policy.html).
* xAI (api.x.ai). [Terms of Service](https://x.ai/legal/terms-of-service-enterprise), [Privacy Policy](https://x.ai/legal/privacy-policy).
* Together AI (api.together.ai). [Terms of Service](https://www.together.ai/terms-of-service), [Privacy Policy](https://www.together.ai/privacy).
* Ollama and custom OpenAI-compatible endpoints: requests go only to the address you enter. If that server is run by a third party, its own terms and privacy policy apply.

== Source code and build ==

The admin app, the chat widget, and the block ship compiled JavaScript and CSS in the `build` folder. The uncompiled source is included in the plugin in the `src` folder (`src/admin` and `src/widget`) and the `blocks` folder, together with `package.json`, `webpack.config.js`, `postcss.config.js`, `tsconfig.json`, and `composer.json`.

To build from source:

1. Install Node.js 22 or newer and Composer.
2. Run `composer install` and `npm install`.
3. Run `npm run build`.

== Credits ==

Chatlume bundles the following open-source libraries in its compiled admin app and chat widget. All are compatible with the GPL.

* Preact (chat widget) – MIT License
* TanStack Query – MIT License
* React Router – MIT License
* React Hook Form and @hookform/resolvers – MIT License
* Zod – MIT License
* Radix UI primitives – MIT License
* Lucide icons (lucide-react) – ISC License
* Sonner – MIT License
* Motion – MIT License
* Tailwind CSS – MIT License
* clsx – MIT License
* tailwind-merge – MIT License
* class-variance-authority – Apache License 2.0

The widget's icons and avatar illustrations are drawn for Chatlume. No third-party logos are included. Provider names are used only to identify the services Chatlume can connect to.
