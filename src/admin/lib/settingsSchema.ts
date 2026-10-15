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

/**
 * One trusted proxy address: an IPv4 or IPv6 address, optionally with a
 * CIDR prefix (the server validates it again).
 */
export const ipRangeSchema = z
	.string()
	.trim()
	.regex(
		/^((\d{1,3}\.){3}\d{1,3}(\/\d{1,2})?|[0-9a-fA-F:]*:[0-9a-fA-F:.]*(\/\d{1,3})?)$/,
		'ip'
	);

export const securitySettingsSchema = z
	.object( {
		rate_limit_messages: z.number().int().min( 1 ).max( 1000 ),
		rate_limit_minutes: z.number().int().min( 1 ).max( 1440 ),
		session_hours: z.number().int().min( 1 ).max( 720 ),
		trusted_proxy: z.enum( [ 'none', 'cloudflare', 'custom' ] ),
		trusted_proxy_ips: z.array( ipRangeSchema ).max( 50 ),
	} )
	.strict();

export const cacheSettingsSchema = z
	.object( {
		enabled: z.boolean(),
		ttl_hours: z.number().int().min( 1 ).max( 720 ),
	} )
	.strict();

export const debugSettingsSchema = z
	.object( {
		enabled: z.boolean(),
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
		security: securitySettingsSchema,
		costs: z.record( z.string(), z.unknown() ),
		cache: cacheSettingsSchema,
		display: displaySettingsSchema,
		streaming: streamingSettingsSchema,
		debug: debugSettingsSchema,
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
 * The General tab of the Settings page.
 */
export const generalSettingsFormSchema = z
	.object( {
		security: securitySettingsSchema,
		streaming: streamingSettingsSchema,
		cache: cacheSettingsSchema,
		privacy: privacySettingsSchema,
		debug: debugSettingsSchema,
	} )
	.strict();

export type GeneralSettingsForm = z.infer< typeof generalSettingsFormSchema >;

/**
 * Sections an exported settings file may restore. `setup` is left out so an
 * import never reopens or skips the setup wizard.
 */
export const IMPORTABLE_SECTIONS = [
	'privacy',
	'providers',
	'chatbot',
	'quick_answers',
	'security',
	'costs',
	'cache',
	'display',
	'streaming',
	'debug',
] as const;

/**
 * The contents of a settings export file.
 *
 * @param settings Current settings.
 * @param version  Plugin version.
 * @return JSON text.
 */
export function exportSettingsFile(
	settings: Record< string, unknown >,
	version: string
): string {
	const sections: Record< string, unknown > = {};
	for ( const section of IMPORTABLE_SECTIONS ) {
		if ( section in settings ) {
			sections[ section ] = settings[ section ];
		}
	}
	return JSON.stringify(
		{
			plugin: 'chatlume-ai-chatbot',
			version,
			exported: new Date().toISOString(),
			settings: sections,
		},
		null,
		2
	);
}

/**
 * The importable sections of a parsed settings file.
 *
 * @param data Parsed JSON.
 * @return Settings update, or null when the file holds no Chatlume settings.
 */
export function importableSettings( data: unknown ): SettingsUpdate | null {
	if ( ! isPlainObject( data ) ) {
		return null;
	}
	const source = isPlainObject( data.settings ) ? data.settings : data;
	const update: Record< string, unknown > = {};
	for ( const section of IMPORTABLE_SECTIONS ) {
		if ( isPlainObject( source[ section ] ) ) {
			update[ section ] = source[ section ];
		}
	}
	return Object.keys( update ).length ? ( update as SettingsUpdate ) : null;
}

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
