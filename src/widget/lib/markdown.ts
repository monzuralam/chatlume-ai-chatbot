/**
 * Allowlist Markdown for bot replies.
 *
 * Model output is parsed into a small tree of known node types (paragraphs,
 * headings, lists, quotes, code blocks, bold, italic, inline code, links,
 * line breaks) that the widget renders as elements. Nothing is ever parsed
 * as HTML: tags in the text stay visible text, and links accept only
 * http(s), mailto, and tel addresses. Unfinished syntax (common while a
 * reply is still streaming) renders as plain text.
 */

export type Inline =
	| { type: 'text'; text: string }
	| { type: 'strong'; children: Inline[] }
	| { type: 'em'; children: Inline[] }
	| { type: 'code'; text: string }
	| { type: 'link'; href: string; children: Inline[] }
	| { type: 'br' };

export type Block =
	| { type: 'paragraph'; children: Inline[] }
	| { type: 'heading'; level: number; children: Inline[] }
	| { type: 'list'; ordered: boolean; start: number; items: Inline[][] }
	| { type: 'code'; lang: string; text: string }
	| { type: 'quote'; children: Inline[] }
	| { type: 'hr' };

const FENCE = /^ {0,3}(`{3,}|~{3,})\s*([\w+#.-]*)\s*$/;
const HEADING = /^ {0,3}(#{1,6})\s+(.*?)\s*#*\s*$/;
const RULE = /^ {0,3}([-*_])(\s*\1){2,}\s*$/;
const QUOTE = /^ {0,3}>\s?(.*)$/;
const ITEM = /^(\s*)([-*+]|(\d{1,9})[.)])\s+(.*)$/;
const AUTOLINK = /^https?:\/\/[^\s<>()[\]]*[^\s<>()[\].,;:!?'"]/;

/**
 * A link target that is safe to open, or null.
 *
 * @param url Raw address.
 * @return Safe address or null.
 */
export function safeHref( url: string ): string | null {
	const value = url.trim();
	if ( /^(mailto|tel):[^\s]+$/i.test( value ) ) {
		return value;
	}
	if ( ! /^https?:\/\//i.test( value ) ) {
		return null;
	}
	try {
		const parsed = new URL( value );
		return parsed.protocol === 'http:' || parsed.protocol === 'https:'
			? parsed.href
			: null;
	} catch {
		return null;
	}
}

/**
 * Parses a reply into blocks.
 *
 * @param source Markdown text.
 * @return Blocks.
 */
export function parseMarkdown( source: string ): Block[] {
	const lines = String( source ?? '' )
		.replace( /\r\n?/g, '\n' )
		.split( '\n' );
	const blocks: Block[] = [];
	let paragraph: string[] = [];

	const flush = () => {
		if ( paragraph.length ) {
			blocks.push( {
				type: 'paragraph',
				children: parseInline( paragraph.join( '\n' ) ),
			} );
			paragraph = [];
		}
	};

	for ( let i = 0; i < lines.length; i++ ) {
		const line = lines[ i ];

		const fence = FENCE.exec( line );
		if ( fence ) {
			flush();
			const marker = fence[ 1 ];
			const code: string[] = [];
			i++;
			while (
				i < lines.length &&
				! new RegExp(
					`^ {0,3}${ marker[ 0 ] === '`' ? '`' : '~' }{${
						marker.length
					},}\\s*$`
				).test( lines[ i ] )
			) {
				code.push( lines[ i ] );
				i++;
			}
			blocks.push( {
				type: 'code',
				lang: fence[ 2 ].toLowerCase(),
				text: code.join( '\n' ),
			} );
			continue;
		}

		if ( line.trim() === '' ) {
			flush();
			continue;
		}

		const heading = HEADING.exec( line );
		if ( heading ) {
			flush();
			blocks.push( {
				type: 'heading',
				level: heading[ 1 ].length,
				children: parseInline( heading[ 2 ] ),
			} );
			continue;
		}

		if ( RULE.test( line ) ) {
			flush();
			blocks.push( { type: 'hr' } );
			continue;
		}

		if ( QUOTE.test( line ) ) {
			flush();
			const quoted: string[] = [];
			while ( i < lines.length && QUOTE.test( lines[ i ] ) ) {
				quoted.push(
					( QUOTE.exec( lines[ i ] ) as RegExpExecArray )[ 1 ]
				);
				i++;
			}
			i--;
			blocks.push( {
				type: 'quote',
				children: parseInline( quoted.join( '\n' ) ),
			} );
			continue;
		}

		const item = ITEM.exec( line );
		if (
			item &&
			( paragraph.length === 0 ||
				item[ 3 ] === undefined ||
				item[ 3 ] === '1' )
		) {
			flush();
			const ordered = item[ 3 ] !== undefined;
			const start = ordered ? parseInt( item[ 3 ], 10 ) : 1;
			const items: string[] = [];
			while ( i < lines.length ) {
				const current = ITEM.exec( lines[ i ] );
				if ( current ) {
					items.push( current[ 4 ] );
				} else if (
					items.length &&
					lines[ i ].trim() !== '' &&
					/^\s+/.test( lines[ i ] )
				) {
					items[ items.length - 1 ] += '\n' + lines[ i ].trim();
				} else {
					break;
				}
				i++;
			}
			i--;
			blocks.push( {
				type: 'list',
				ordered,
				start,
				items: items.map( ( text ) => parseInline( text ) ),
			} );
			continue;
		}

		paragraph.push( line );
	}

	flush();
	return blocks;
}

/**
 * Index of the closing delimiter, or -1.
 *
 * @param text      Text.
 * @param delimiter Delimiter.
 * @param from      Start index.
 * @return Index.
 */
function closing( text: string, delimiter: string, from: number ): number {
	let index = text.indexOf( delimiter, from );
	while ( index !== -1 ) {
		const before = text[ index - 1 ];
		const after = text[ index + delimiter.length ];
		const valid =
			index > from &&
			before !== ' ' &&
			before !== '\\' &&
			( delimiter[ 0 ] !== '_' ||
				! after ||
				! /[\p{L}\p{N}]/u.test( after ) );
		if ( valid ) {
			return index;
		}
		index = text.indexOf( delimiter, index + 1 );
	}
	return -1;
}

/**
 * Parses inline formatting.
 *
 * @param text Text.
 * @return Inline nodes.
 */
export function parseInline( text: string ): Inline[] {
	const nodes: Inline[] = [];
	let buffer = '';

	const pushText = ( value: string ) => {
		buffer += value;
	};
	const flush = () => {
		if ( buffer ) {
			nodes.push( { type: 'text', text: buffer } );
			buffer = '';
		}
	};

	let i = 0;
	while ( i < text.length ) {
		const char = text[ i ];
		const rest = text.slice( i );

		if (
			char === '\\' &&
			i + 1 < text.length &&
			/[\\`*_[\]()#+\-.!>~]/.test( text[ i + 1 ] )
		) {
			pushText( text[ i + 1 ] );
			i += 2;
			continue;
		}

		if ( char === '\n' ) {
			flush();
			nodes.push( { type: 'br' } );
			i++;
			continue;
		}

		if ( char === '`' ) {
			const run = /^`+/.exec( rest )![ 0 ];
			const end = text.indexOf( run, i + run.length );
			if ( end !== -1 ) {
				flush();
				nodes.push( {
					type: 'code',
					text: text.slice( i + run.length, end ).trim(),
				} );
				i = end + run.length;
				continue;
			}
			pushText( run );
			i += run.length;
			continue;
		}

		if ( rest.startsWith( '**' ) || rest.startsWith( '__' ) ) {
			const delimiter = rest.slice( 0, 2 );
			const openOk =
				delimiter === '**' ||
				i === 0 ||
				! /[\p{L}\p{N}]/u.test( text[ i - 1 ] );
			const end =
				openOk && text[ i + 2 ] !== ' '
					? closing( text, delimiter, i + 2 )
					: -1;
			if ( end !== -1 ) {
				flush();
				nodes.push( {
					type: 'strong',
					children: parseInline( text.slice( i + 2, end ) ),
				} );
				i = end + 2;
				continue;
			}
		}

		if ( char === '*' || char === '_' ) {
			const openOk =
				char === '*' ||
				i === 0 ||
				! /[\p{L}\p{N}]/u.test( text[ i - 1 ] );
			const end =
				openOk &&
				text[ i + 1 ] &&
				text[ i + 1 ] !== ' ' &&
				text[ i + 1 ] !== char
					? closing( text, char, i + 1 )
					: -1;
			if ( end !== -1 ) {
				flush();
				nodes.push( {
					type: 'em',
					children: parseInline( text.slice( i + 1, end ) ),
				} );
				i = end + 1;
				continue;
			}
		}

		if ( char === '[' ) {
			const link =
				/^\[([^\]\n]+)\]\(\s*<?([^\s)>]+)>?(?:\s+"[^"]*")?\s*\)/.exec(
					rest
				);
			if ( link ) {
				const href = safeHref( link[ 2 ] );
				flush();
				const label = parseInline( link[ 1 ] );
				if ( href ) {
					nodes.push( { type: 'link', href, children: label } );
				} else {
					nodes.push( ...label );
				}
				i += link[ 0 ].length;
				continue;
			}
		}

		if (
			( char === 'h' || char === 'H' ) &&
			( i === 0 || /[\s(]/.test( text[ i - 1 ] ) )
		) {
			const url = AUTOLINK.exec( rest );
			const href = url ? safeHref( url[ 0 ] ) : null;
			if ( url && href ) {
				flush();
				nodes.push( {
					type: 'link',
					href,
					children: [ { type: 'text', text: url[ 0 ] } ],
				} );
				i += url[ 0 ].length;
				continue;
			}
		}

		pushText( char );
		i++;
	}

	flush();
	return nodes;
}

/**
 * Converts the welcome message (bold, italics, links, and line breaks only,
 * already filtered by the server) into inline nodes. The HTML is parsed into
 * an inert document and only allowlisted elements are kept.
 *
 * @param html Welcome message.
 * @return Inline nodes.
 */
export function parseRichText( html: string ): Inline[] {
	const doc = new DOMParser().parseFromString(
		`<body>${ String( html ?? '' ) }</body>`,
		'text/html'
	);

	const walk = ( parent: Node ): Inline[] => {
		const nodes: Inline[] = [];
		parent.childNodes.forEach( ( node ) => {
			if ( node.nodeType === Node.TEXT_NODE ) {
				const parts = ( node.textContent ?? '' ).split( '\n' );
				parts.forEach( ( part, index ) => {
					if ( index > 0 ) {
						nodes.push( { type: 'br' } );
					}
					if ( part ) {
						nodes.push( { type: 'text', text: part } );
					}
				} );
				return;
			}
			if ( node.nodeType !== Node.ELEMENT_NODE ) {
				return;
			}
			const element = node as Element;
			const tag = element.tagName.toLowerCase();
			if ( tag === 'br' ) {
				nodes.push( { type: 'br' } );
			} else if ( tag === 'strong' || tag === 'b' ) {
				nodes.push( { type: 'strong', children: walk( element ) } );
			} else if ( tag === 'em' || tag === 'i' ) {
				nodes.push( { type: 'em', children: walk( element ) } );
			} else if ( tag === 'a' ) {
				const href = safeHref( element.getAttribute( 'href' ) ?? '' );
				const children = walk( element );
				if ( href ) {
					nodes.push( { type: 'link', href, children } );
				} else {
					nodes.push( ...children );
				}
			} else if ( tag !== 'script' && tag !== 'style' ) {
				nodes.push( ...walk( element ) );
			}
		} );
		return nodes;
	};

	return walk( doc.body );
}

/**
 * Plain text of inline nodes (for copying and announcements).
 *
 * @param nodes Inline nodes.
 * @return Text.
 */
export function inlineText( nodes: Inline[] ): string {
	return nodes
		.map( ( node ) => {
			switch ( node.type ) {
				case 'text':
				case 'code':
					return node.text;
				case 'br':
					return '\n';
				default:
					return inlineText( node.children );
			}
		} )
		.join( '' );
}

/**
 * Plain text of a Markdown reply (for copying and screen reader announcements).
 *
 * @param source Markdown text.
 * @return Text.
 */
export function markdownText( source: string ): string {
	return parseMarkdown( source )
		.map( ( block ) => {
			switch ( block.type ) {
				case 'code':
					return block.text;
				case 'hr':
					return '';
				case 'list':
					return block.items
						.map(
							( item, index ) =>
								( block.ordered
									? `${ block.start + index }. `
									: '• ' ) + inlineText( item )
						)
						.join( '\n' );
				default:
					return inlineText( block.children );
			}
		} )
		.filter( Boolean )
		.join( '\n\n' );
}
