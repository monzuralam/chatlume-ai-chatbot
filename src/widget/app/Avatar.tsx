/**
 * The bot avatar: an image, an illustration, or initials.
 */
import { safeUrl } from '../lib/theme';
import type { ThemeUi } from '../types';
import { Illustration } from './icons';

/**
 * Up to two initials from a name.
 *
 * @param name Name.
 * @return Initials.
 */
export function initials( name: string ): string {
	const words = name.trim().split( /\s+/ ).filter( Boolean );
	const letters = words
		.slice( 0, 2 )
		.map( ( word ) => Array.from( word )[ 0 ] ?? '' )
		.join( '' );
	return ( letters || '•' ).toLocaleUpperCase();
}

/**
 * Avatar.
 *
 * @param props       Props.
 * @param props.brand Brand options.
 * @param props.name  Bot name (for initials).
 * @param props.size  Size class.
 * @return Avatar.
 */
export function Avatar( {
	brand,
	name,
	size = 'md',
}: {
	brand: ThemeUi[ 'brand' ];
	name: string;
	size?: 'sm' | 'md';
} ) {
	const url = brand.avatarType === 'image' ? safeUrl( brand.avatarUrl ) : '';

	return (
		<span className={ `cl-avatar cl-avatar-${ size }` } aria-hidden="true">
			{ url && <img src={ url } alt="" decoding="async" /> }
			{ ! url && brand.avatarType === 'illustration' && (
				<Illustration name={ brand.illustration } />
			) }
			{ ! url && brand.avatarType !== 'illustration' && (
				<span className="cl-initials">{ initials( name ) }</span>
			) }
		</span>
	);
}
