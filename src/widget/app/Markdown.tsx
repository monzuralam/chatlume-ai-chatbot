/**
 * Renders parsed Markdown as elements (never as HTML).
 */
import { useMemo, useState } from 'react';

import { parseMarkdown, type Block, type Inline } from '../lib/markdown';
import type { WidgetStrings } from '../types';
import { copyText } from './copy';
import { Icon } from './icons';

/**
 * Inline nodes.
 *
 * @param props         Props.
 * @param props.nodes   Nodes.
 * @param props.strings Widget strings.
 * @return Elements.
 */
export function InlineNodes( {
	nodes,
	strings,
}: {
	nodes: Inline[];
	strings: WidgetStrings;
} ) {
	return (
		<>
			{ nodes.map( ( node, index ) => {
				switch ( node.type ) {
					case 'text':
						return node.text;
					case 'br':
						return <br key={ index } />;
					case 'code':
						return <code key={ index }>{ node.text }</code>;
					case 'strong':
						return (
							<strong key={ index }>
								<InlineNodes
									nodes={ node.children }
									strings={ strings }
								/>
							</strong>
						);
					case 'em':
						return (
							<em key={ index }>
								<InlineNodes
									nodes={ node.children }
									strings={ strings }
								/>
							</em>
						);
					case 'link':
						return (
							<a
								key={ index }
								href={ node.href }
								target="_blank"
								rel="noopener noreferrer"
							>
								<InlineNodes
									nodes={ node.children }
									strings={ strings }
								/>
								<span className="cl-sr">
									{ ' ' }
									{ strings.externalLink }
								</span>
							</a>
						);
				}
				return null;
			} ) }
		</>
	);
}

/**
 * A code block with a copy button.
 *
 * @param props         Props.
 * @param props.block   Code block.
 * @param props.strings Widget strings.
 * @return Code block.
 */
function CodeBlock( {
	block,
	strings,
}: {
	block: Extract< Block, { type: 'code' } >;
	strings: WidgetStrings;
} ) {
	const [ copied, setCopied ] = useState( false );

	return (
		<div className="cl-code">
			<div className="cl-code-bar">
				<span>{ block.lang }</span>
				<button
					type="button"
					className="cl-code-copy"
					onClick={ () =>
						copyText( block.text ).then( ( ok ) => {
							setCopied( ok );
							window.setTimeout( () => setCopied( false ), 1600 );
						} )
					}
				>
					<Icon name={ copied ? 'check' : 'copy' } />
					<span>{ copied ? strings.copied : strings.copyCode }</span>
				</button>
			</div>
			<pre>
				<code>{ block.text }</code>
			</pre>
		</div>
	);
}

/**
 * A Markdown reply.
 *
 * @param props         Props.
 * @param props.text    Markdown text.
 * @param props.strings Widget strings.
 * @return Elements.
 */
export function Markdown( {
	text,
	strings,
}: {
	text: string;
	strings: WidgetStrings;
} ) {
	const blocks = useMemo( () => parseMarkdown( text ), [ text ] );

	return (
		<div className="cl-md">
			{ blocks.map( ( block, index ) => {
				switch ( block.type ) {
					case 'paragraph':
						return (
							<p key={ index }>
								<InlineNodes
									nodes={ block.children }
									strings={ strings }
								/>
							</p>
						);
					case 'heading':
						return (
							<p
								key={ index }
								className={ `cl-h cl-h${ Math.min( block.level, 3 ) }` }
							>
								<InlineNodes
									nodes={ block.children }
									strings={ strings }
								/>
							</p>
						);
					case 'quote':
						return (
							<blockquote key={ index }>
								<InlineNodes
									nodes={ block.children }
									strings={ strings }
								/>
							</blockquote>
						);
					case 'hr':
						return <hr key={ index } />;
					case 'code':
						return (
							<CodeBlock
								key={ index }
								block={ block }
								strings={ strings }
							/>
						);
					case 'list': {
						const items = block.items.map( ( item, itemIndex ) => (
							<li key={ itemIndex }>
								<InlineNodes
									nodes={ item }
									strings={ strings }
								/>
							</li>
						) );
						return block.ordered ? (
							<ol key={ index } start={ block.start }>
								{ items }
							</ol>
						) : (
							<ul key={ index }>{ items }</ul>
						);
					}
				}
				return null;
			} ) }
		</div>
	);
}
