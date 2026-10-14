import { cn } from '../../lib/utils';

/**
 * Chatlume mark: a speech bubble with a spark, matching the menu icon.
 *
 * @param props           Props.
 * @param props.className Extra classes.
 */
export function Logo( { className }: { className?: string } ) {
	return (
		<span
			aria-hidden="true"
			className={ cn(
				'chatlume:inline-flex chatlume:size-8 chatlume:items-center chatlume:justify-center chatlume:rounded-[10px]',
				'chatlume:bg-accent chatlume:text-accent-fg chatlume:shadow-card',
				className
			) }
		>
			<svg
				viewBox="0 0 20 20"
				className="chatlume:size-[18px]"
				fill="currentColor"
			>
				<path
					fillRule="evenodd"
					d="M10 2c4.42 0 8 3.13 8 7s-3.58 7-8 7c-.9 0-1.77-.13-2.58-.37L3.5 17.5l1.08-3.24C3.03 12.98 2 11.1 2 9c0-3.87 3.58-7 8-7Zm0 2.8.95 2.45 2.45.95-2.45.95L10 11.6l-.95-2.45-2.45-.95 2.45-.95L10 4.8Z"
				/>
			</svg>
		</span>
	);
}
