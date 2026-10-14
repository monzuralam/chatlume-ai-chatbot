import type { ChatlumeAdminApi } from '../extensions/registry';
import type { ChatlumeBootstrap } from '../lib/bootstrap';

declare global {
	interface Window {
		chatlume?: {
			bootstrap?: ChatlumeBootstrap;
			admin?: ChatlumeAdminApi;
		};
	}

	// Set at runtime so lazy chunks load from the plugin URL (see public-path.ts).
	// Webpack runtime global; its name is fixed by webpack.
	// eslint-disable-next-line camelcase
	var __webpack_public_path__: string;
}

export {};
