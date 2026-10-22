/**
 * Typed access to the boot data printed by PHP (Admin\Assets::bootstrap_data).
 */
import type { WidgetConfig } from '../../widget/types';

export type ColorMode = 'system' | 'light' | 'dark';

export type EncryptionStatus = 'ok' | 'key_changed' | 'unavailable';

export interface SystemStatus {
	wpVersion: string;
	phpVersion: string;
	dbVersion: number;
	dbVersionExpected: number;
	fulltext: boolean;
	encryption: EncryptionStatus;
	isMultisite: boolean;
	features: {
		abilitiesApi: boolean;
		aiClient: boolean;
		connectors: boolean;
		fontLibrary: boolean;
		commandPalette: boolean;
	};
}

export interface ChatlumeBootstrap {
	version: string;
	restNamespace: string;
	assetsUrl: string;
	adminUrl: string;
	siteUrl: string;
	/** Published privacy policy page, or empty. */
	privacyUrl: string;
	restNonce: string;
	setupCompleted: boolean;
	docsUrl: string;
	colorMode: ColorMode;
	user: {
		id: number;
		displayName: string;
	};
	isRtl: boolean;
	system: SystemStatus;
	/** Widget configuration for live previews; null until the widget is built. */
	widget: WidgetConfig | null;
}

const FALLBACK: ChatlumeBootstrap = {
	version: '',
	restNamespace: 'chatlume/v1',
	assetsUrl: '',
	adminUrl: '',
	siteUrl: '',
	privacyUrl: '',
	restNonce: '',
	setupCompleted: true,
	docsUrl: '',
	colorMode: 'system',
	user: { id: 0, displayName: '' },
	isRtl: false,
	widget: null,
	system: {
		wpVersion: '',
		phpVersion: '',
		dbVersion: 0,
		dbVersionExpected: 0,
		fulltext: false,
		encryption: 'ok',
		isMultisite: false,
		features: {
			abilitiesApi: false,
			aiClient: false,
			connectors: false,
			fontLibrary: false,
			commandPalette: false,
		},
	},
};

/**
 * Boot data, or safe defaults when it is missing (e.g. in tests).
 */
export function getBootstrap(): ChatlumeBootstrap {
	return window.chatlume?.bootstrap ?? FALLBACK;
}
