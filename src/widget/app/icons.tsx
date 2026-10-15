/**
 * Line icons used inside the chat window (24×24, drawn for Chatlume).
 */
const PATHS = {
	send: 'M5 12h13M12 5l7 7-7 7',
	stop: 'M7 7h10v10H7z',
	copy: 'M9 9h10v11H9zM5 15V4h10',
	check: 'M5 12.5l4.5 4.5L19 7.5',
	up: 'M7 11v9H4v-9zM7 11l4-7c1.7 0 2.7 1.2 2.4 2.8L13 9h5.4c1.3 0 2.2 1.2 1.9 2.4l-1.6 6.4c-.2.7-.9 1.2-1.6 1.2H7',
	down: 'M7 13V4H4v9zM7 13l4 7c1.7 0 2.7-1.2 2.4-2.8L13 15h5.4c1.3 0 2.2-1.2 1.9-2.4l-1.6-6.4c-.2-.7-.9-1.2-1.6-1.2H7',
	more: 'M12 6h.01M12 12h.01M12 18h.01',
	close: 'M6 6l12 12M18 6L6 18',
	plus: 'M12 5v14M5 12h14',
	retry: 'M4 12a8 8 0 1 0 2.4-5.7M4 4v5h5',
	external: 'M14 5h5v5M19 5l-8 8M17 14v5H5V7h5',
	sparkle:
		'M12 4l1.6 4.6L18 10l-4.4 1.4L12 16l-1.6-4.6L6 10l4.4-1.4zM18.5 16l.6 1.6 1.4.4-1.4.5-.6 1.5-.5-1.5-1.5-.5 1.5-.4z',
	mail: 'M4 6h16v12H4zM4 7l8 6 8-6',
	phone: 'M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a1 1 0 0 1-1 1A16 16 0 0 1 4 5a1 1 0 0 1 1-1z',
	link: 'M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1',
	chat: 'M4 5h16v11H9l-5 4z',
} as const;

export type IconName = keyof typeof PATHS;

/**
 * An icon.
 *
 * @param props      Props.
 * @param props.name Icon name.
 * @return Icon.
 */
export function Icon( { name }: { name: IconName } ) {
	return (
		<svg
			className="cl-icon"
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth={ name === 'more' ? 3 : 2 }
			strokeLinecap="round"
			strokeLinejoin="round"
			aria-hidden="true"
			focusable="false"
		>
			<path d={ PATHS[ name ] } />
		</svg>
	);
}

/**
 * Built-in avatar illustrations (simple shapes on the primary color).
 */
const ILLUSTRATIONS: Record< string, string > = {
	bubbles:
		'M9 8h8a3 3 0 0 1 3 3v3a3 3 0 0 1-3 3h-1l-2 2v-2H9a3 3 0 0 1-3-3v-3a3 3 0 0 1 3-3z',
	robot: 'M8 9h8a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2zM12 9V6M10 13h.01M14 13h.01',
	spark: 'M12 5l1.8 5.2L19 12l-5.2 1.8L12 19l-1.8-5.2L5 12l5.2-1.8z',
	leaf: 'M6 18c0-7 5-12 12-12 0 7-5 12-12 12zM6 18l6-6',
	wave: 'M5 10c2-2 3.5-2 5 0s3 2 5 0 3-2 4 0M5 15c2-2 3.5-2 5 0s3 2 5 0 3-2 4 0',
	planet: 'M12 17a5 5 0 1 0 0-10 5 5 0 0 0 0 10zM4 15c-1.5 2 6 1 10-1.5S21 7 19.5 7',
};

/**
 * An avatar illustration.
 *
 * @param props      Props.
 * @param props.name Illustration name.
 * @return Illustration.
 */
export function Illustration( { name }: { name: string } ) {
	return (
		<svg
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth={ 1.8 }
			strokeLinecap="round"
			strokeLinejoin="round"
			aria-hidden="true"
			focusable="false"
		>
			<path d={ ILLUSTRATIONS[ name ] ?? ILLUSTRATIONS.bubbles } />
		</svg>
	);
}
