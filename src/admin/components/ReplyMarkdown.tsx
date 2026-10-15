import { __ } from '@wordpress/i18n';
import { createElement, useMemo } from 'react';

import { parseMarkdown, type Inline } from '../../widget/lib/markdown';

/**
 * Inline Markdown nodes.
 *
 * @param props       Props.
 * @param props.nodes Nodes.
 */
function InlineNodes( { nodes }: { nodes: Inline[] } ) {
	return (
		<>
			{ nodes.map( ( node, index ) => {
				switch ( node.type ) {
					case 'text':
						return node.text;
					case 'br':
						return <br key={ index } />;
					case 'code':
						return (
							<code
								key={ index }
								className="chatlume:rounded chatlume:bg-surface chatlume:px-1 chatlume:font-mono chatlume:text-[0.9em]"
							>
								{ node.text }
							</code>
						);
					case 'strong':
						return (
							<strong key={ index }>
								<InlineNodes nodes={ node.children } />
							</strong>
						);
					case 'em':
						return (
							<em key={ index }>
								<InlineNodes nodes={ node.children } />
							</em>
						);
					case 'link':
						return (
							<a
								key={ index }
								href={ node.href }
								target="_blank"
								rel="noopener noreferrer"
								className="chatlume:text-accent chatlume:underline"
							>
								<InlineNodes nodes={ node.children } />
								<span className="chatlume:sr-only">
									{ ' ' }
									{ __(
										'(opens in a new tab)',
										'chatlume-ai-chatbot'
									) }
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
 * A bot reply rendered with the same allowlist Markdown as the widget, so
 * the Playground shows what visitors see. Nothing is parsed as HTML.
 *
 * @param props      Props.
 * @param props.text Markdown text.
 */
export function ReplyMarkdown( { text }: { text: string } ) {
	const blocks = useMemo( () => parseMarkdown( text ), [ text ] );

	return (
		<div className="chatlume:flex chatlume:flex-col chatlume:gap-2">
			{ blocks.map( ( block, index ) => {
				switch ( block.type ) {
					case 'heading':
						return createElement(
							`h${ Math.min( 6, Math.max( 3, block.level + 2 ) ) }`,
							{
								key: index,
								className: 'chatlume:font-semibold',
							},
							<InlineNodes nodes={ block.children } />
						);
					case 'list': {
						const items = block.items.map( ( item, itemIndex ) => (
							<li key={ itemIndex }>
								<InlineNodes nodes={ item } />
							</li>
						) );
						return block.ordered ? (
							<ol
								key={ index }
								start={ block.start }
								className="chatlume:list-decimal chatlume:ps-5"
							>
								{ items }
							</ol>
						) : (
							<ul
								key={ index }
								className="chatlume:list-disc chatlume:ps-5"
							>
								{ items }
							</ul>
						);
					}
					case 'code':
						return (
							<pre
								key={ index }
								className="chatlume:overflow-x-auto chatlume:rounded-control chatlume:bg-surface chatlume:p-2.5 chatlume:font-mono chatlume:text-xs"
							>
								<code>{ block.text }</code>
							</pre>
						);
					case 'quote':
						return (
							<blockquote
								key={ index }
								className="chatlume:border-s-2 chatlume:border-border chatlume:ps-3 chatlume:text-fg-muted"
							>
								<InlineNodes nodes={ block.children } />
							</blockquote>
						);
					case 'hr':
						return (
							<hr
								key={ index }
								className="chatlume:border-border"
							/>
						);
					default:
						return (
							<p key={ index } className="chatlume:m-0">
								<InlineNodes nodes={ block.children } />
							</p>
						);
				}
			} ) }
		</div>
	);
}
