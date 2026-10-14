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
	} )
	.strict();

export const settingsSchema = z
	.object( {
		setup: setupSettingsSchema,
		privacy: privacySettingsSchema,
	} )
	.strict();

export type Settings = z.infer< typeof settingsSchema >;

/**
 * A partial update: any subset of sections, each with any subset of fields.
 */
export type SettingsUpdate = {
	[ Section in keyof Settings ]?: Partial< Settings[ Section ] >;
};

/**
 * Form shown on the Settings page ("Data & privacy").
 */
export const dataPrivacyFormSchema = z
	.object( {
		privacy: privacySettingsSchema,
	} )
	.strict();

export type DataPrivacyForm = z.infer< typeof dataPrivacyFormSchema >;

/**
 * Deep-merges a partial update into settings (used for optimistic updates;
 * mirrors Settings::merge_known() in PHP).
 *
 * @param current Current settings.
 * @param update  Partial update.
 */
export function mergeSettings(
	current: Settings,
	update: SettingsUpdate
): Settings {
	const next = { ...current } as Record< string, Record< string, unknown > >;

	for ( const [ section, values ] of Object.entries( update ) ) {
		if ( section in next && values ) {
			next[ section ] = { ...next[ section ], ...values };
		}
	}

	return next as unknown as Settings;
}
