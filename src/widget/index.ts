/**
 * Chatlume widget launcher.
 *
 * A tiny, dependency-free entry loaded with `defer`. It defines the
 * `<chatlume-widget>` element (Shadow DOM), applies the design tokens on
 * first paint, renders the floating launcher, and loads the chat app with a
 * dynamic `import()` on first open (or when idle, if preloading is on).
 *
 * Public API: `window.Chatlume.open()`, `.close()`, `.toggle()`,
 * `.send( text )`, and `.mountPreview( element, config )`.
 */
import launcherCss from './styles/launcher.css?inline';
import { CLOSE_ICON, LAUNCHER_ICONS, svgIcon } from './launcher/icons';
import { showFloating } from './lib/display';
import { loadFonts } from './lib/fonts';
import { read, write } from './lib/storage';
import { pageDirection, resolveMode, safeUrl, themeCss } from './lib/theme';
import type { ChatApp, ThemeConfig, WidgetConfig, WidgetHost } from './types';

// eslint-disable-next-line camelcase, @typescript-eslint/no-unused-vars -- webpack's documented runtime variable (assigned, read by webpack).
declare let __webpack_public_path__: string;

const TAG = 'chatlume-widget';

type AppModule = typeof import( './app/mount' );
let appModule: Promise< AppModule > | null = null;

/**
 * Loads the chat app chunk once.
 *
 * @return The app module.
 */
function loadApp(): Promise< AppModule > {
	if ( ! appModule ) {
		appModule = import( /* webpackChunkName: "app" */ './app/mount' );
		appModule.catch( () => {
			appModule = null;
		} );
	}
	return appModule;
}

/**
 * Runs a callback when the browser is idle.
 *
 * @param callback Callback.
 */
function whenIdle( callback: () => void ): void {
	if ( 'requestIdleCallback' in window ) {
		window.requestIdleCallback( callback, { timeout: 5000 } );
	} else {
		setTimeout( callback, 2000 );
	}
}

/**
 * Creates an element with a class.
 *
 * @param tag       Tag name.
 * @param className Class.
 * @return Element.
 */
function el< K extends keyof HTMLElementTagNameMap >(
	tag: K,
	className: string
): HTMLElementTagNameMap[ K ] {
	const node = document.createElement( tag );
	node.className = className;
	return node;
}

let primary: Controller | null = null;

/**
 * One widget instance: floating, inline (shortcode/block), or preview.
 */
class Controller implements WidgetHost {
	config: WidgetConfig;
	element: HTMLElement;
	root: ShadowRoot;
	mount: HTMLElement;
	inline: boolean;
	preview: boolean;
	site: boolean;
	launcher: HTMLButtonElement | null = null;

	private wrap: HTMLDivElement;
	private tokens: HTMLStyleElement;
	private opened = false;
	private app: ChatApp | null = null;
	private loading: Promise< ChatApp > | null = null;
	private pending: string[] = [];
	private listeners = new Set< ( open: boolean ) => void >();
	private cleanups: Array< () => void > = [];
	private compactQuery: MediaQueryList | null = null;
	private unread: HTMLElement | null = null;
	private teaser: HTMLElement | null = null;
	private savedOverflow: string | null = null;

	constructor( element: HTMLElement, config: WidgetConfig ) {
		this.element = element;
		this.config = config;
		this.inline = element.hasAttribute( 'data-inline' );
		this.preview = element.hasAttribute( 'data-preview' );
		this.site = this.preview && element.hasAttribute( 'data-site' );
		this.root =
			element.shadowRoot ?? element.attachShadow( { mode: 'open' } );
		this.root.replaceChildren();

		const base = document.createElement( 'style' );
		base.textContent = launcherCss;
		this.tokens = document.createElement( 'style' );
		this.wrap = el( 'div', 'cl-root' );
		this.mount = el( 'div', 'cl-mount' );
		this.wrap.append( this.mount );
		this.root.append( base, this.tokens, this.wrap );

		this.wrap.dir = pageDirection();
		if ( this.inline ) {
			this.wrap.dataset.inline = '';
		}
		if ( element.hasAttribute( 'data-contained' ) ) {
			this.wrap.dataset.contained = '';
		}

		const scheme =
			typeof window.matchMedia === 'function'
				? window.matchMedia( '(prefers-color-scheme: dark)' )
				: null;
		if ( scheme ) {
			const onScheme = () => this.applyMode();
			scheme.addEventListener( 'change', onScheme );
			this.cleanups.push( () =>
				scheme.removeEventListener( 'change', onScheme )
			);
		}

		this.setTheme( config.theme );

		if ( ( ! this.preview || this.site ) && ! primary ) {
			primary = this;
		}

		if ( this.inline || ( this.preview && ! this.site ) ) {
			this.startEmbedded();
		} else if ( config.preload ) {
			whenIdle( () => {
				loadApp().catch( () => undefined );
			} );
		}
	}

	isOpen(): boolean {
		return this.opened;
	}

	open(): void {
		if ( this.opened ) {
			return;
		}
		this.opened = true;
		this.wrap.dataset.open = '';
		this.syncLauncherState();
		this.hideTeaser( false );
		if ( this.unread ) {
			this.unread.hidden = true;
		}
		if ( ! this.inline && ! this.preview ) {
			write( 'seen', true );
		}
		this.syncScrollLock();
		this.emit();
		this.ensureApp()
			.then( ( app ) => {
				if ( this.opened ) {
					app.focus();
				}
			} )
			.catch( () => this.close() );
	}

	close(): void {
		if ( ! this.opened || this.inline ) {
			return;
		}
		const hadFocus =
			this.element.ownerDocument.activeElement === this.element;
		this.opened = false;
		delete this.wrap.dataset.open;
		this.syncLauncherState();
		this.syncScrollLock();
		this.emit();
		if ( hadFocus ) {
			this.launcher?.focus();
		}
	}

	toggle(): void {
		if ( this.opened ) {
			this.close();
		} else {
			this.open();
		}
	}

	send( text: string ): void {
		const message = String( text ?? '' ).trim();
		if ( message === '' ) {
			return;
		}
		this.open();
		if ( this.app ) {
			this.app.send( message );
		} else {
			this.pending.push( message );
		}
	}

	notify(): void {
		if ( ! this.opened && this.unread ) {
			this.unread.hidden = false;
		}
	}

	onToggle( listener: ( open: boolean ) => void ): () => void {
		this.listeners.add( listener );
		return () => this.listeners.delete( listener );
	}

	setDirection( direction: 'ltr' | 'rtl' ): void {
		this.wrap.dir = direction;
	}

	setTheme( theme: ThemeConfig ): void {
		if ( ! theme || ! theme.ui ) {
			return;
		}
		this.config = { ...this.config, theme };
		loadFonts( theme.fonts );
		this.tokens.textContent = themeCss( theme );
		this.wrap.dataset.position = theme.ui.position;
		this.wrap.dataset.style = this.inline ? 'inline' : theme.ui.style;
		this.applyMode();
		this.watchCompact( theme.ui.fullscreenBelow );
		if ( ! this.inline ) {
			this.renderLauncher();
		}
		this.app?.update( { theme } );
	}

	/**
	 * Loads and mounts the chat app once.
	 *
	 * @return The mounted app.
	 */
	ensureApp(): Promise< ChatApp > {
		if ( ! this.loading ) {
			this.launcher?.setAttribute( 'aria-busy', 'true' );
			this.loading = loadApp()
				.then( ( module ) => {
					const app = module.mount( this );
					this.app = app;
					this.pending
						.splice( 0 )
						.forEach( ( text ) => app.send( text ) );
					return app;
				} )
				.finally( () => this.launcher?.removeAttribute( 'aria-busy' ) );
			this.loading.catch( () => {
				this.loading = null;
			} );
		}
		return this.loading;
	}

	destroy(): void {
		this.cleanups.splice( 0 ).forEach( ( cleanup ) => cleanup() );
		this.compactQuery = null;
		this.app?.unmount();
		this.app = null;
		this.loading = null;
		if ( this.opened ) {
			this.opened = false;
			this.syncScrollLock();
		}
		if ( primary === this ) {
			primary = null;
		}
	}

	/**
	 * Inline and preview widgets are always open; inline ones load when
	 * they come near the viewport.
	 */
	private startEmbedded(): void {
		const begin = () => this.open();
		if ( this.preview || ! ( 'IntersectionObserver' in window ) ) {
			begin();
			return;
		}
		const observer = new IntersectionObserver(
			( entries ) => {
				if ( entries.some( ( entry ) => entry.isIntersecting ) ) {
					observer.disconnect();
					begin();
				}
			},
			{ rootMargin: '300px' }
		);
		observer.observe( this.element );
		this.cleanups.push( () => observer.disconnect() );
	}

	private applyMode(): void {
		this.element.dataset.mode = resolveMode( this.config.theme.ui.mode );
	}

	private watchCompact( below: number ): void {
		if ( typeof window.matchMedia !== 'function' ) {
			return;
		}
		const width = Math.max( 0, Math.round( Number( below ) || 0 ) );
		const query = window.matchMedia( `(max-width: ${ width }px)` );
		if ( this.compactQuery?.media === query.media ) {
			this.applyCompact();
			return;
		}
		this.compactQuery = query;
		const onChange = () => this.applyCompact();
		query.addEventListener( 'change', onChange );
		this.cleanups.push( () =>
			query.removeEventListener( 'change', onChange )
		);
		this.applyCompact();
	}

	private applyCompact(): void {
		const compact =
			! this.inline &&
			! this.wrap.hasAttribute( 'data-contained' ) &&
			!! this.compactQuery?.matches;
		this.toggleData( 'compact', compact );
		this.toggleData(
			'fullscreen',
			compact && this.config.theme.ui.mobileFullscreen
		);
		this.syncScrollLock();
	}

	private toggleData( name: string, on: boolean ): void {
		if ( on ) {
			this.wrap.dataset[ name ] = '';
		} else {
			delete this.wrap.dataset[ name ];
		}
	}

	/**
	 * Locks page scrolling behind a full-screen window or a modal.
	 */
	private syncScrollLock(): void {
		const style = this.config.theme.ui.style;
		const lock =
			this.opened &&
			! this.inline &&
			! this.wrap.hasAttribute( 'data-contained' ) &&
			( this.wrap.hasAttribute( 'data-fullscreen' ) ||
				style === 'modal' );
		const html = document.documentElement;
		if ( lock && this.savedOverflow === null ) {
			this.savedOverflow = html.style.overflow;
			html.style.overflow = 'hidden';
		} else if ( ! lock && this.savedOverflow !== null ) {
			html.style.overflow = this.savedOverflow;
			this.savedOverflow = null;
		}
	}

	private renderLauncher(): void {
		const { ui } = this.config.theme;
		const options = ui.launcher;
		const strings = this.config.strings;

		if ( ! this.launcher ) {
			this.launcher = el( 'button', 'cl-launcher' );
			this.launcher.type = 'button';
			this.launcher.setAttribute( 'part', 'launcher' );
			this.launcher.setAttribute( 'aria-haspopup', 'dialog' );
			this.launcher.addEventListener( 'click', () => this.toggle() );
			this.wrap.append( this.launcher );
		}

		const button = this.launcher;
		button.replaceChildren();
		button.dataset.shape = options.shape;
		button.dataset.type = options.type;
		button.dataset.attention = options.attention;

		const icons = el( 'span', 'cl-launcher-icons' );
		const openIcon = el( 'span', 'cl-icon-open' );
		let imageUrl = '';
		if ( options.type === 'avatar' ) {
			imageUrl = safeUrl( ui.brand.avatarUrl );
		} else if ( options.type === 'image' ) {
			imageUrl = safeUrl( options.image );
		}
		if ( imageUrl ) {
			const image = document.createElement( 'img' );
			image.src = imageUrl;
			image.alt = '';
			image.decoding = 'async';
			openIcon.append( image );
		} else {
			openIcon.append(
				svgIcon( LAUNCHER_ICONS[ options.icon ] ?? LAUNCHER_ICONS.chat )
			);
		}
		const closeIcon = el( 'span', 'cl-icon-close' );
		closeIcon.append( svgIcon( CLOSE_ICON ) );
		icons.append( openIcon, closeIcon );
		button.append( icons );

		if ( options.shape === 'pill' && options.label ) {
			const label = el( 'span', 'cl-launcher-label' );
			label.textContent = options.label;
			button.append( label );
		}

		this.unread = el( 'span', 'cl-unread' );
		this.unread.hidden = true;
		const unreadText = el( 'span', 'cl-sr' );
		unreadText.textContent = strings.newMessage ?? '';
		this.unread.append( unreadText );
		button.append( this.unread );

		const seen = this.preview ? false : read( 'seen', false );
		if ( options.attention === 'badge' && ! seen ) {
			const badge = el( 'span', 'cl-badge' );
			badge.textContent = '1';
			badge.setAttribute( 'aria-hidden', 'true' );
			button.append( badge );
		}

		this.renderTeaser( options.teaser );
		this.syncLauncherState();
	}

	private renderTeaser( text: string ): void {
		this.teaser?.remove();
		this.teaser = null;
		const dismissed = this.preview ? false : read( 'teaser', '' ) === text;
		if ( ! text || dismissed || this.opened ) {
			return;
		}

		const teaser = el( 'div', 'cl-teaser' );
		teaser.setAttribute( 'part', 'teaser' );
		const message = el( 'span', 'cl-teaser-text' );
		message.textContent = text;
		message.addEventListener( 'click', () => this.open() );
		const close = el( 'button', 'cl-teaser-close' );
		close.type = 'button';
		close.setAttribute( 'aria-label', this.config.strings.close ?? '' );
		close.append( svgIcon( CLOSE_ICON ) );
		close.addEventListener( 'click', ( event ) => {
			event.stopPropagation();
			this.hideTeaser( true );
		} );
		teaser.append( message, close );
		this.wrap.append( teaser );
		this.teaser = teaser;
	}

	private hideTeaser( remember: boolean ): void {
		if ( ! this.teaser ) {
			return;
		}
		if ( remember && ! this.preview ) {
			write( 'teaser', this.config.theme.ui.launcher.teaser );
		}
		this.teaser.remove();
		this.teaser = null;
	}

	private syncLauncherState(): void {
		if ( ! this.launcher ) {
			return;
		}
		const strings = this.config.strings;
		this.launcher.setAttribute( 'aria-expanded', String( this.opened ) );
		this.launcher.setAttribute(
			'aria-label',
			( this.opened ? strings.close : strings.open ) ?? ''
		);
		if ( this.opened ) {
			this.launcher.querySelector( '.cl-badge' )?.remove();
		}
	}

	private emit(): void {
		this.listeners.forEach( ( listener ) => listener( this.opened ) );
	}
}

/**
 * The `<chatlume-widget>` element.
 */
class ChatlumeWidget extends HTMLElement {
	/** Configuration for this instance (preview); defaults to the page configuration. */
	chatlumeConfig?: WidgetConfig;
	controller?: Controller;

	connectedCallback(): void {
		if ( this.controller ) {
			return;
		}
		const config = this.chatlumeConfig ?? window.chatlumeWidgetConfig;
		if ( config && config.theme ) {
			this.controller = new Controller( this, config );
		}
	}

	disconnectedCallback(): void {
		this.controller?.destroy();
		this.controller = undefined;
	}
}

/**
 * Mounts a widget in preview mode inside an element (the Customizer).
 *
 * @param element Container (positioned; the widget stays inside it).
 * @param config  Widget configuration.
 * @return The mounted app.
 */
function mountPreview(
	element: HTMLElement,
	config: WidgetConfig
): Promise< ChatApp > {
	define();
	if ( config.assets && ! window.chatlumeWidgetConfig ) {
		// eslint-disable-next-line camelcase -- webpack's documented runtime variable.
		__webpack_public_path__ = config.assets;
	}
	const widget = document.createElement( TAG ) as ChatlumeWidget;
	widget.chatlumeConfig = {
		...config,
		preview: config.preview ?? {},
	};
	widget.setAttribute( 'data-preview', '' );
	widget.setAttribute( 'data-contained', '' );
	element.append( widget );
	return widget.controller
		? widget.controller.ensureApp()
		: Promise.reject( new Error( 'Chatlume preview could not start.' ) );
}

/**
 * Draft preview on a real page: inside the Customizer's frame (pinned open,
 * updated by the Customizer) or in a tab of its own (a normal floating
 * widget with sample replies and a notice).
 *
 * @param config Page configuration.
 */
function startDraftPreview( config: WidgetConfig ): void {
	const widget = document.createElement( TAG );
	widget.setAttribute( 'data-preview', '' );
	if ( config.previewMode === 'site' ) {
		widget.setAttribute( 'data-site', '' );
	}
	document.body.append( widget );

	const text = config.preview?.badge;
	if ( text ) {
		const badge = document.createElement( 'div' );
		badge.setAttribute( 'role', 'status' );
		badge.textContent = text;
		badge.style.cssText =
			'position:fixed;top:12px;left:50%;transform:translateX(-50%);z-index:2147483647;padding:8px 14px;border-radius:999px;background:#111827;color:#fff;font:500 13px/1.4 system-ui,sans-serif;box-shadow:0 4px 16px rgba(0,0,0,.2);pointer-events:none;max-width:calc(100vw - 32px);text-align:center';
		document.body.append( badge );
	}
}

/**
 * Registers the custom element once.
 */
function define(): void {
	if ( ! customElements.get( TAG ) ) {
		customElements.define( TAG, ChatlumeWidget );
	}
}

/**
 * Boots the launcher.
 */
function start(): void {
	if (
		! ( 'customElements' in window ) ||
		! ( 'attachShadow' in Element.prototype )
	) {
		return;
	}

	const config = window.chatlumeWidgetConfig;
	if ( config?.assets ) {
		// eslint-disable-next-line camelcase -- webpack's documented runtime variable.
		__webpack_public_path__ = config.assets;
	}

	window.Chatlume = {
		open: () => primary?.open(),
		close: () => primary?.close(),
		toggle: () => primary?.toggle(),
		send: ( text: string ) => primary?.send( text ),
		mountPreview,
	};

	define();

	// Draft preview on the site (only the admin who asked gets this configuration).
	if ( config?.previewMode ) {
		startDraftPreview( config );
		return;
	}

	// An inline chat on the page replaces the floating launcher.
	const hasInline = !! document.querySelector( `${ TAG }[data-inline]` );
	if (
		config?.floating &&
		! hasInline &&
		! primary &&
		showFloating( config.display, window.innerWidth )
	) {
		document.body.append( document.createElement( TAG ) );
	}
}

start();
