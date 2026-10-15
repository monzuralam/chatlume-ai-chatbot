// Types for the parts of the block editor package that the block uses
// (the package ships no TypeScript declarations).
declare module '@wordpress/block-editor' {
	import type { ComponentType, CSSProperties, ReactNode } from 'react';

	/**
	 * Props for the block wrapper.
	 *
	 * @param props           Extra props.
	 * @param props.className Class name.
	 * @param props.style     Inline style.
	 * @return Wrapper props.
	 */
	export function useBlockProps( props?: {
		className?: string;
		style?: CSSProperties;
	} ): Record< string, unknown >;

	export const InspectorControls: ComponentType< { children?: ReactNode } >;
}
