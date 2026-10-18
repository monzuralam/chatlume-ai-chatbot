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

export const quickActionSchema = z
	.object( {
		label: z.string().max( 40 ).default( '' ),
		type: z
			.enum( [ 'url', 'email', 'phone', 'whatsapp', 'message' ] )
			.default( 'url' ),
		value: z.string().max( 500 ).default( '' ),
	} )
	.strict();

export const chatbotSettingsSchema = z
	.object( {
		bot_name: z.string().max( 60 ),
		preset: z.enum( [ 'support', 'sales', 'faq', 'blank' ] ),
		system_prompt: z.string().max( 8000 ),
		welcome_message: z.string().max( 1000 ),
		starter_questions: z.array( z.string().max( 120 ) ).max( 4 ),
		quick_actions: z.array( quickActionSchema ).max( 4 ),
		temperature: z.number().min( 0 ).max( 2 ),
		max_output_tokens: z.number().int().min( 50 ).max( 4000 ),
		history_window: z.number().int().min( 0 ).max( 30 ),
		max_message_length: z.number().int().min( 50 ).max( 4000 ),
		language_mode: z.enum( [ 'visitor', 'fixed' ] ),
		fixed_language: z.string().max( 40 ),
		site_topics_only: z.boolean(),
		refusal_message: z.string().max( 500 ),
		blocked_words: z.array( z.string().max( 60 ) ).max( 200 ),
		offline_message: z.string().max( 500 ),
		error_message: z.string().max( 500 ),
		site_search_message: z.string().max( 500 ),
	} )
	.strict();

export const quickAnswersSettingsSchema = z
	.object( {
		enabled: z.boolean(),
		threshold: z.number().min( 0.5 ).max( 1 ),
		show_as_starters: z.boolean(),
	} )
	.strict();

export const costsSettingsSchema = z
	.object( {
		conversation_message_cap: z.number().int().min( 0 ).max( 1000 ),
		budget_unit: z.enum( [ 'currency', 'tokens' ] ),
		daily_budget: z.number().min( 0 ).max( 1_000_000_000 ),
		monthly_budget: z.number().min( 0 ).max( 1_000_000_000 ),
		cap_behavior: z.enum( [ 'site_search', 'offline', 'fallback_model' ] ),
		alert_email: z.union( [ z.literal( '' ), z.email().max( 100 ) ] ),
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

const timeSchema = z.string().regex( /^([01]\d|2[0-3]):[0-5]\d$/ );

export const dayHoursSchema = z
	.object( {
		open: z.boolean(),
		start: timeSchema,
		end: timeSchema,
	} )
	.strict();

export const DAY_KEYS = [
	'mon',
	'tue',
	'wed',
	'thu',
	'fri',
	'sat',
	'sun',
] as const;

export type DayKey = ( typeof DAY_KEYS )[ number ];

export const displaySettingsSchema = z
	.object( {
		enabled: z.boolean(),
		preload: z.boolean(),
		pages_mode: z.enum( [ 'all', 'include', 'exclude' ] ),
		page_ids: z.array( z.number().int().min( 0 ) ).max( 200 ),
		post_types: z.array( z.string().max( 20 ) ).max( 50 ),
		url_patterns: z.array( z.string().min( 1 ).max( 200 ) ).max( 50 ),
		devices: z.enum( [ 'all', 'desktop', 'mobile' ] ),
		visitors: z.enum( [ 'all', 'logged_in', 'logged_out' ] ),
		roles: z.array( z.string().max( 60 ) ).max( 50 ),
		hours: z
			.object( {
				enabled: z.boolean(),
				behavior: z.enum( [ 'offline', 'hide' ] ),
				days: z
					.object( {
						mon: dayHoursSchema,
						tue: dayHoursSchema,
						wed: dayHoursSchema,
						thu: dayHoursSchema,
						fri: dayHoursSchema,
						sat: dayHoursSchema,
						sun: dayHoursSchema,
					} )
					.strict(),
			} )
			.strict(),
	} )
	.strict();

export const knowledgeSettingsSchema = z
	.object( {
		post_types: z.array( z.string().max( 20 ) ).max( 30 ),
		include_ids: z.array( z.number().int().min( 0 ) ).max( 500 ),
		exclude_ids: z.array( z.number().int().min( 0 ) ).max( 500 ),
		business_info: z.string().max( 20000 ),
		use_embeddings: z.boolean(),
		top_k: z.number().int().min( 1 ).max( 10 ),
		max_upload_mb: z.number().int().min( 1 ).max( 50 ),
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
		chatbot: chatbotSettingsSchema,
		quick_answers: quickAnswersSettingsSchema,
		security: securitySettingsSchema,
		costs: costsSettingsSchema,
		cache: cacheSettingsSchema,
		display: displaySettingsSchema,
		streaming: streamingSettingsSchema,
		debug: debugSettingsSchema,
		knowledge: knowledgeSettingsSchema,
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
	'knowledge',
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
