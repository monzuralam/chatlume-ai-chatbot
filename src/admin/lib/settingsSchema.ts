/**
 * Zod schemas for settings. They mirror the PHP schema in
 * includes/Settings/SettingsSchema.php; keep both in sync.
 */
import { z } from 'zod';

export const setupSettingsSchema = z
	.object( {
		completed: z.boolean(),
	} )
	.strict();

export const privacySettingsSchema = z
	.object( {
		delete_data_on_uninstall: z.boolean(),
		store_conversations: z.boolean(),
		retention_days: z.number().int().min( 0 ).max( 3650 ),
	} )
	.strict();

export const roleSchema = z
	.object( {
		provider: z.string(),
		model: z.string(),
	} )
	.strict();

export const providersSettingsSchema = z
	.object( {
		primary: roleSchema,
		fallback: roleSchema,
		embeddings: roleSchema.extend( { dimensions: z.number().int() } ),
		// Per-provider settings; each provider defines its own fields.
		configs: z.record( z.string(), z.record( z.string(), z.unknown() ) ),
	} )
	.strict();

export const displaySettingsSchema = z
	.object( {
		enabled: z.boolean(),
		preload: z.boolean(),
	} )
	.strict();

export const streamingSettingsSchema = z
	.object( {
		enabled: z.boolean(),
		max_concurrent: z.number().int().min( 1 ).max( 20 ),
		max_duration: z.number().int().min( 10 ).max( 300 ),
		first_token_timeout: z.number().int().min( 5 ).max( 120 ),
	} )
	.strict();

export const settingsSchema = z
	.object( {
		setup: setupSettingsSchema,
		privacy: privacySettingsSchema,
		providers: providersSettingsSchema,
		// Validated on the server; typed in detail when their admin pages are built.
		chatbot: z.record( z.string(), z.unknown() ),
		quick_answers: z.record( z.string(), z.unknown() ),
		security: z.record( z.string(), z.unknown() ),
		costs: z.record( z.string(), z.unknown() ),
		cache: z.record( z.string(), z.unknown() ),
		display: displaySettingsSchema,
		streaming: streamingSettingsSchema,
	} )
	.strict();

export type Settings = z.infer< typeof settingsSchema >;

type DeepPartial< T > = T extends unknown[]
	? T
	: T extends object
		? { [ K in keyof T ]?: DeepPartial< T[ K ] > }
		: T;

/**
 * A partial update: any subset of sections and nested fields.
 */
export type SettingsUpdate = DeepPartial< Settings >;

/**
 * Form shown on the Settings page ("Data & privacy").
 */
export const dataPrivacyFormSchema = z
	.object( {
		privacy: privacySettingsSchema,
	} )
	.strict();

export type DataPrivacyForm = z.infer< typeof dataPrivacyFormSchema >;

function isPlainObject( value: unknown ): value is Record< string, unknown > {
	return (
		typeof value === 'object' && value !== null && ! Array.isArray( value )
	);
}

/**
 * Deep-merges a partial update into a value: plain objects merge, lists and
 * scalars are replaced (mirrors Settings::merge_known() in PHP).
 *
 * @param current Current value.
 * @param update  Partial update.
 */
function deepMerge(
	current: Record< string, unknown >,
	update: Record< string, unknown >
): Record< string, unknown > {
	const next: Record< string, unknown > = { ...current };

	for ( const [ key, value ] of Object.entries( update ) ) {
		if ( value === undefined ) {
			continue;
		}
		next[ key ] =
			isPlainObject( value ) && isPlainObject( current[ key ] )
				? deepMerge(
						current[ key ] as Record< string, unknown >,
						value
					)
				: value;
	}

	return next;
}

/**
 * Applies a partial update to settings, ignoring unknown sections
 * (used for optimistic updates).
 *
 * @param current Current settings.
 * @param update  Partial update.
 */
export function mergeSettings(
	current: Settings,
	update: SettingsUpdate
): Settings {
	const known: Record< string, unknown > = {};

	for ( const [ section, value ] of Object.entries( update ) ) {
		if ( section in current ) {
			known[ section ] = value;
		}
	}

	return deepMerge(
		current as unknown as Record< string, unknown >,
		known
	) as unknown as Settings;
}
