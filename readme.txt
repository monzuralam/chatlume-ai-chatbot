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

This is an early development version. It installs the plugin's database tables, settings storage, secure key storage, and the Chatlume admin app. Chat features are added in upcoming releases.

* A modern admin app with light and dark mode.
* Secure, encrypted storage for API keys, derived from your site's security keys.
* Multisite support: every site in a network keeps its own settings and data.
* No ads, upsell banners, tracking, or remote code.

== Installation ==

1. Upload the `chatlume-ai-chatbot` folder to `/wp-content/plugins/`, or install the plugin from the Plugins screen.
2. Activate the plugin through the Plugins screen.
3. Open **Chatlume** in the admin menu.

== Frequently Asked Questions ==

= Does Chatlume send data to external services? =

Not in this version. See the External services section below.

= What happens to my data if I delete the plugin? =

Nothing is removed unless you enable **Delete all data on uninstall** in Chatlume > Settings. With that option on, deleting the plugin removes Chatlume's database tables and settings.

== Screenshots ==

1. The Chatlume admin home screen with system status.

== Changelog ==

= 0.1.0 =
* Initial development release: plugin foundation, database tables, settings storage, encrypted key storage, and the admin app shell.

== External services ==

This version of Chatlume does not connect to any external service. Connections to AI providers are added in later versions and will be documented here, including what data is sent, when, and links to each provider's terms and privacy policy.

== Source code and build ==

The admin app ships compiled JavaScript and CSS in the `build` folder. The uncompiled source is included in the plugin in the `src` folder, together with `package.json`, `webpack.config.js`, `postcss.config.js`, `tsconfig.json`, and `composer.json`.

To build from source:

1. Install Node.js 22 or newer and Composer.
2. Run `composer install` and `npm install`.
3. Run `npm run build`.

== Credits ==

Chatlume bundles the following open-source libraries in its compiled admin app. All are compatible with the GPL.

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
