/**
 * Editor registration of the chatlume/chat block.
 *
 * The block is rendered on the server (render.php); the editor shows a
 * lightweight preview with a height control.
 */
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { registerBlockType, type BlockConfiguration } from '@wordpress/blocks';
import { PanelBody, RangeControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import metadata from './block.json';

type Attributes = Record< string, unknown > & {
	height: number;
};

function Edit( {
	attributes,
	setAttributes,
}: {
	attributes: Attributes;
	setAttributes: ( next: Partial< Attributes > ) => void;
} ) {
	const blockProps = useBlockProps( {
		style: {
			height: `${ attributes.height }px`,
			maxHeight: '80vh',
			display: 'flex',
			flexDirection: 'column',
			border: '1px solid #dcdcde',
			borderRadius: '12px',
			overflow: 'hidden',
			background: '#fff',
			fontSize: '14px',
		},
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Size', 'chatlume-ai-chatbot' ) }>
					<RangeControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Height (pixels)', 'chatlume-ai-chatbot' ) }
						value={ attributes.height }
						min={ 320 }
						max={ 1200 }
						step={ 10 }
						onChange={ ( height ) =>
							setAttributes( { height: height ?? 600 } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<div
					style={ {
						padding: '14px 16px',
						background: '#4f46e5',
						color: '#fff',
						fontWeight: 600,
					} }
				>
					{ __( 'Chat', 'chatlume-ai-chatbot' ) }
				</div>
				<div
					style={ {
						flex: 1,
						padding: '16px',
						color: '#50575e',
						display: 'flex',
						alignItems: 'center',
						justifyContent: 'center',
						textAlign: 'center',
					} }
				>
					{ __(
						'The Chatlume chat appears here on your site. Its look follows your Chatlume appearance settings.',
						'chatlume-ai-chatbot'
					) }
				</div>
				<div
					style={ {
						margin: '12px',
						padding: '10px 14px',
						border: '1px solid #dcdcde',
						borderRadius: '999px',
						color: '#8c8f94',
					} }
				>
					{ __( 'Type your message…', 'chatlume-ai-chatbot' ) }
				</div>
			</div>
		</>
	);
}

registerBlockType( metadata as BlockConfiguration< Attributes >, {
	edit: Edit,
	save: () => null,
} );
