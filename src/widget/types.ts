/**
 * Shapes shared by the widget launcher and the chat app.
 *
 * The theme part mirrors `Chatlume\Appearance\TokenResolver::resolve()`.
 */

export type ColorMode = 'light' | 'dark' | 'auto';
export type WidgetStyle = 'floating' | 'panel' | 'modal';

export interface ThemeUi {
	mode: ColorMode;
	style: WidgetStyle;
	position: 'right' | 'left';
	mobileFullscreen: boolean;
	fullscreenBelow: number;
	header: 'solid' | 'gradient' | 'minimal';
	messages: {
		style: 'bubbles' | 'flat' | 'outlined';
		avatar: boolean;
		timestamps: 'hover' | 'always' | 'never';
		animate: boolean;
	};
	background: {
		type: 'solid' | 'pattern' | 'gradient';
		pattern: 'dots' | 'grid' | 'waves' | 'diagonal';
		glass: boolean;
	};
	composer: {
		style: 'rounded' | 'bar';
		sendButton: 'icon' | 'text';
	};
	launcher: {
		type: 'icon' | 'avatar' | 'image';
		icon: string;
		image: string;
		shape: 'circle' | 'square' | 'pill';
		size: 's' | 'm' | 'l';
		label: string;
		attention: 'none' | 'pulse' | 'bounce' | 'badge';
		teaser: string;
	};
	motion: 'scale' | 'slide' | 'none';
	brand: {
		avatarType: 'initials' | 'image' | 'illustration';
		avatarUrl: string;
		illustration: string;
		logoUrl: string;
		showLogo: boolean;
		headerTitle: string;
		headerSubtitle: string;
		statusText: string;
		showAvatar: boolean;
		showStatus: boolean;
		poweredBy: boolean;
	};
}

/** A font face the widget registers (see `Chatlume\Appearance\FontCatalog`). */
export interface FontFaceSpec {
	family: string;
	src: string;
	weight: string;
	style: string;
	unicodeRange: string;
}

export interface ThemeConfig {
	schema: number;
	light: Record< string, string >;
	dark: Record< string, string >;
	vars: Record< string, string >;
	ui: ThemeUi;
	/** Faces of the fonts in use (empty for system fonts). */
	fonts?: FontFaceSpec[];
	v: string;
}

export type WidgetStrings = Record< string, string >;

export interface DayHours {
	open: boolean;
	start: string;
	end: string;
}

/** A proactive invitation (see `Chatlume\Frontend\DisplayRules::triggers()`). */
export interface TriggerConfig {
	action: 'teaser' | 'open';
	message: string;
	/** Seconds on the page (0: not used). */
	delay: number;
	/** Percent scrolled (0: not used). */
	scroll: number;
	exit: boolean;
	frequency: 'session' | 'day' | 'week' | 'always';
	skipReturning: boolean;
}

/** Rules applied in the browser (see `Chatlume\Frontend\DisplayRules::client_config()`). */
export interface DisplayConfig {
	devices: 'all' | 'desktop' | 'mobile';
	hours: {
		enabled: boolean;
		behavior: 'offline' | 'hide';
		timezone: string;
		days: Partial< Record< string, DayHours > >;
	};
	/** Null when invitations are off or not for this page. */
	triggers?: TriggerConfig | null;
}

export interface WidgetConfig {
	rest: string;
	assets: string;
	version: string;
	floating: boolean;
	preload: boolean;
	/** Missing in previews. */
	display?: DisplayConfig;
	streaming: {
		enabled: boolean;
		firstTokenTimeout: number;
	};
	theme: ThemeConfig;
	strings: WidgetStrings;
	/** Set only in preview mode. */
	preview?: PreviewOptions;
	/** Draft preview on the site: inside the Customizer (frame) or in a tab (site). */
	previewMode?: 'frame' | 'site';
	/** Chat events for the site's own analytics. */
	analytics?: boolean;
	/** Cloudflare Turnstile site key ('' when off). */
	turnstile?: string;
}

export interface QuickAction {
	label: string;
	type: 'url' | 'email' | 'phone' | 'whatsapp' | 'message';
	href?: string;
	message?: string;
}

/** Behavior settings from POST /session (never cached in page HTML). */
export interface BehaviorConfig {
	bot_name: string;
	welcome_message: string;
	starter_questions: string[];
	quick_actions: QuickAction[];
	max_message_length: number;
	store_conversations: boolean;
	/** Microphone button (where the browser supports it). Missing from older cached configs. */
	voice_input?: boolean;
	/** "Read aloud" on answers (where the browser supports it). */
	read_aloud?: boolean;
	/** Lead form, or null when lead capture is off. */
	lead?: LeadConfig | null;
	/** "Talk to a person", or null when off. */
	handoff?: HandoffConfig | null;
	/** Visitors may email the conversation to the email they gave. */
	transcript?: boolean;
}

/** Lead capture (see `Chatlume\Engagement\Leads::config()`). */
export interface LeadConfig {
	timing: 'before' | 'after';
	/** Visitor messages before the form (timing "after"). */
	after: number;
	/** The form must be filled in before chatting. */
	required: boolean;
	ask_name: boolean;
	/** Consent checkbox text (bold, italics, links); empty for none. */
	consent: string;
	/** Whether this session already gave its details. */
	given: boolean;
}

/** Ways to reach a person (see `Chatlume\Engagement\Handoff::config()`). */
export interface HandoffConfig {
	actions: Array< {
		type: 'contact' | 'email' | 'phone' | 'whatsapp';
		label: string;
		href: string;
	} >;
	email_team: boolean;
}

export interface Source {
	title: string;
	url: string;
}

export type MessageStatus =
	'pending' | 'streaming' | 'done' | 'error' | 'stopped';

export interface ChatMessage {
	id: string;
	role: 'user' | 'bot';
	text: string;
	time: number;
	status: MessageStatus;
	source?: string;
	sources?: Source[];
	quickAnswerId?: number | null;
	serverId?: number;
	feedback?: -1 | 0 | 1;
	/** Error text shown with a Retry button. */
	error?: string;
	/** The question a bot message answers (used by Retry and "Ask AI"). */
	question?: string;
}

/** States the Customizer can force in preview mode. */
export type PreviewState =
	| 'launcher'
	| 'empty'
	| 'conversation'
	| 'streaming'
	| 'typing'
	| 'error'
	| 'offline'
	| 'lead';

/** Sample content for the preview, supplied (translated) by the Customizer. */
export interface PreviewSamples {
	question: string;
	/** Markdown answer showing formatting: bold, a list, a code block. */
	answer: string;
	sources: Source[];
	quickQuestion: string;
	quickAnswer: string;
	/** Reply used when the admin types in the preview. */
	reply: string;
}

export interface PreviewOptions {
	/** Origin allowed to send preview messages (defaults to this page's origin). */
	origin?: string;
	state?: PreviewState;
	behavior?: Partial< BehaviorConfig >;
	samples?: Partial< PreviewSamples >;
	/** Real AI replies through the admin-only Playground endpoint (Customizer "Live AI replies"). */
	live?: { url: string; nonce: string } | null;
	/** Notice shown on a draft preview of the site. */
	badge?: string;
}

/** What the launcher hands to the lazily loaded chat app. */
export interface WidgetHost {
	config: WidgetConfig;
	element: HTMLElement;
	root: ShadowRoot;
	/** Element the app renders into. */
	mount: HTMLElement;
	inline: boolean;
	preview: boolean;
	/** Draft preview in a site tab: a normal floating widget with sample replies. */
	site: boolean;
	isOpen: () => boolean;
	open: () => void;
	close: () => void;
	/** Applies a (new) theme without re-rendering. */
	setTheme: ( theme: ThemeConfig ) => void;
	/** Text direction (preview RTL toggle). */
	setDirection: ( direction: 'ltr' | 'rtl' ) => void;
	/** Shows the unread dot on the launcher while closed. */
	notify: () => void;
	/** Subscribes to open/close changes. */
	onToggle: ( listener: ( open: boolean ) => void ) => () => void;
	/** Element that receives focus back when the window closes. */
	launcher: HTMLElement | null;
}

/** API the chat app returns once mounted. */
export interface ChatApp {
	send: ( text: string ) => void;
	focus: () => void;
	update: ( config: Partial< WidgetConfig > ) => void;
	setState: ( state: PreviewState | null ) => void;
	unmount: () => void;
}

declare global {
	interface Window {
		chatlumeWidgetConfig?: WidgetConfig;
		Chatlume?: {
			open: () => void;
			close: () => void;
			toggle: () => void;
			send: ( text: string ) => void;
			mountPreview: (
				element: HTMLElement,
				config: WidgetConfig
			) => Promise< ChatApp >;
		};
		dataLayer?: unknown[];
	}
}
