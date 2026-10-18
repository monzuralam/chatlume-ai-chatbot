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

= Where does voice input go? =

Voice input and read-aloud use the visitor's own browser; Chatlume never receives or stores audio. Read-aloud uses the voices installed on the device. For speech recognition, some browsers (such as Google Chrome) send the audio to their maker's online service to turn it into text. You can turn either feature off in Chatlume > Chatbot > Voice.

= What content does the bot learn from? =

Published, public, non-password-protected pages and posts by default, and WooCommerce products when WooCommerce is active (with their current price, sale, and stock status). You can choose other public content types, add or leave out single items, and add a short text about your business such as opening hours and policies. Drafts, private content, and password-protected pages are never indexed. Developers can change or leave out any item with the `chatlume_knowledge_sources` filter.

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
* Dashboard: conversations, questions, tokens, estimated cost, and money saved for today, 7, or 30 days; a daily chart; connection and budget status; questions your bot could not answer with one-click quick answers; recent conversations; and a setup checklist.
* Test your bot: chat with your bot in the admin and see why each answer was given (quick answer, cache, AI, or site search), with knowledge used, tokens, cost, time, and the final prompt; save or improve any answer as a quick answer.
* Display: show the chat button on all pages, only on chosen pages, or everywhere except them (pages, content types, and addresses with wildcards); by device, logged-in status, and role; business hours in your timezone with an offline message or a hidden button outside them; copy-ready shortcode and block instructions.
* Customize: a live editor for the chat widget with Simple and Advanced modes, colors generated from your brand color with automatic contrast checks, 10 presets, layout, shape, launcher and motion options, undo and redo, private drafts, publishing, and the last 10 versions.
* Customize fonts: seven bundled open-license fonts (including Bengali and Arabic), your theme's fonts, the WordPress Font Library, or your own woff2 upload, with separate heading fonts and readable fallbacks for every language.
* Customize on your real site: preview the widget on any page at desktop, tablet, or phone size, click any part to edit it, compare with the published look, try live AI replies, preview the draft on your site, match your website's branding in one click, save your own presets, import and export themes, and copy CSS snippets for developers.
* Setup wizard: choose an AI provider, follow the steps to get a key, connect and test it, pick a model and your colors, with a live preview the whole way, or skip the AI and start with quick answers and site search.
* Command Palette: open any Chatlume screen from anywhere in the admin with Ctrl+K (Cmd+K on Mac), for example "Chatlume: Conversations" or "Chatlume: Test your bot".
* Knowledge base: your published pages and posts (or any other public content types you choose, single items included or excluded) and a short text about your business are indexed automatically, kept up to date as you edit, and rebuilt in the background without slowing your site. Multilingual sites using WPML or Polylang keep each language separate.
* Knowledge search: the bot answers from the most relevant passages of your content, found by keywords (with plurals and word forms) and, when an embeddings provider is set up, ranked by meaning; Chinese, Japanese, Thai, and other languages written without spaces are supported. Answers list up to three source links.
* Knowledge documents: upload PDF, Word (DOCX), text, Markdown, CSV, and JSON files (up to 10 MB by default) for the bot to learn from. Only the extracted text is kept; the files themselves are never saved on your site.
* WooCommerce: products visible in your shop are indexed automatically with their price as shoppers see it (including sales and price ranges), stock status, categories, SKU, attributes, and descriptions, and updated whenever a product, its stock, or a scheduled sale changes.
* Knowledge page: choose what the bot learns from, upload documents by drag and drop, manage quick answers with inline editing and CSV import and export, and follow the index size, re-indexing progress, and embeddings in one place.
* Proactive invitations: invite visitors with a message bubble or an opened chat after some time on the page, after scrolling, or when they are about to leave, on every page or only chosen ones, as often as you allow.
* Voice: visitors can speak their questions and have answers read aloud, using their browser's built-in speech features.
* Lead capture: ask for a name and email before chatting or after a few messages, with an optional consent checkbox and an email to your team for each new lead.
* Talk to a person: visitors can reach you through your contact page, email, phone, or WhatsApp, or send the conversation to your team, who can reply by email. Visitors can also email a conversation to themselves.
* Integrations: signed webhooks for new leads, ended conversations, answers rated not helpful, and requests for a person (for Zapier, Make, n8n, or your CRM), with a delivery log and test button; email notification settings with a test email; optional chat events for Google Tag Manager and other analytics on your page; and optional Cloudflare Turnstile bot protection.
* Caching and optimization plugins: publishing a new look or changing what the chat shows clears the page cache of WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, and SiteGround Optimizer, and the chat scripts are kept out of JavaScript combine, minify, and delay features in WP Rocket, LiteSpeed Cache, Autoptimize, Perfmatters, and SiteGround Optimizer.
* Widget texts: reword any label visitors see in the chat, and on sites using WPML or Polylang, translate the welcome message, starter questions, quick actions, messages, invitation, consent text, and header texts into each language. The chat follows the language of the page.
* WordPress 7.0 and newer: AI providers connected in Settings > Connectors can be chosen in Chatlume without entering their keys again (Chatlume uses them through WordPress and never stores those keys).
* WordPress 6.9 and newer: Chatlume abilities let AI agents and automation tools ask the chatbot, read its statistics, list questions it could not answer, and add quick answers, for users who can manage Chatlume.

== External services ==

Chatlume connects to the AI provider(s) that you, the site administrator, set up under Chatlume > Providers. Nothing is sent to any provider until you add one, and only the providers you configure are ever contacted.

**What is sent and when**

* When you load or refresh a provider's model list: your API key (as an authentication header) to that provider's model list endpoint.
* When you test a connection: your API key and a short test prompt ("Reply with the single word OK.").
* When the chatbot answers a visitor: the visitor's message, the recent conversation, your system prompt, the current page's title and URL, the visitor's display name if your prompt uses {user_name}, and matching excerpts from your site's content, sent to the primary provider (and to the fallback provider if the primary fails). Quick answers, cached answers, and site-search results are served without contacting any provider.
* Only when embeddings are switched on and an embeddings provider and model are selected: the title and text of each indexed item (in passages of about 800 characters), sent to the embeddings provider in the background when the item is indexed or changes, and each visitor question, sent to the same provider to find the most relevant passages. Repeated questions are answered from a cache without contacting the provider.
* OpenRouter only, and only if you turn on "Send app attribution": your site's address and name.
* Only if you choose a provider connected in WordPress (Settings > Connectors): the same chat data is sent to that provider through WordPress's AI Client, which adds the key you saved there. The provider's own terms and privacy policy apply (see the list below for OpenAI, Anthropic, and Google).
* Only if you add webhooks under Chatlume > Integrations: the events you choose (lead name and email, the conversation, or the rated answer) to the addresses you enter.
* Only if you turn on Cloudflare Turnstile under Chatlume > Integrations and enter your own Cloudflare keys: when a visitor starts a chat, their browser loads Cloudflare's Turnstile script from challenges.cloudflare.com, which checks that the visitor is human; your site then sends the resulting token, your Turnstile secret key, and the visitor's IP address to Cloudflare to verify it.

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
* Cloudflare Turnstile (challenges.cloudflare.com), only when you turn it on. [Website and Online Services Terms](https://www.cloudflare.com/website-terms/), [Privacy Policy](https://www.cloudflare.com/privacypolicy/), [Turnstile Privacy Addendum](https://www.cloudflare.com/turnstile-privacy-policy/).
* Webhooks: requests go only to the addresses you enter; the receiving service's own terms and privacy policy apply.
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

PHP libraries (in vendor-prefixed, renamed under the Chatlume\Vendor namespace so they never clash with other plugins; each license is included next to its files):

* smalot/pdfparser (reads the text of uploaded PDF documents) – GNU Lesser General Public License 3.0
* symfony/polyfill-mbstring (required by pdfparser) – MIT License

Bundled fonts (assets/fonts, woff2 from the Fontsource packages), each under the SIL Open Font License 1.1, which is GPL-compatible; each font's license is included next to its files:

* Inter – Copyright 2016 The Inter Project Authors
* DM Sans – Copyright 2014 The DM Sans Project Authors
* Manrope – Copyright 2019 The Manrope Project Authors
* Plus Jakarta Sans – Copyright 2020 The Plus Jakarta Sans Project Authors
* Lora – Copyright 2011 The Lora Project Authors
* Noto Sans Bengali – Copyright 2025 The Noto Project Authors
* Noto Sans Arabic – Copyright 2022 The Noto Project Authors

The widget's icons and avatar illustrations are drawn for Chatlume. No third-party logos are included. Provider names are used only to identify the services Chatlume can connect to.
