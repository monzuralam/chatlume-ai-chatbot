/**
 * Types for the part of the WordPress commands package that Chatlume uses
 * (the package ships without TypeScript declarations).
 */
declare module '@wordpress/commands' {
	import type { StoreDescriptor } from '@wordpress/data';

	export interface WPCommandConfig {
		name: string;
		label: string;
		searchLabel?: string;
		callback: ( options: { close: () => void } ) => void;
	}

	export const store: StoreDescriptor< {
		reducer: () => unknown;
		actions: {
			registerCommand: ( config: WPCommandConfig ) => void;
			unregisterCommand: ( name: string ) => void;
		};
	} >;
}
