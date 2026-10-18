/**
 * Window header: avatar, title, status, menu, close.
 */
import { useEffect, useRef, useState } from 'react';

import { safeUrl } from '../lib/theme';
import type { ThemeUi, WidgetStrings } from '../types';
import { Avatar } from './Avatar';
import { Icon } from './icons';

interface Props {
	ui: ThemeUi;
	strings: WidgetStrings;
	title: string;
	titleId: string;
	closable: boolean;
	onClose: () => void;
	onNewChat: () => void;
	/** Outside business hours. */
	closed?: boolean;
	/** Shows ways to reach a person (when handoff is on). */
	onHandoff?: () => void;
	/** Emails the conversation to the visitor (when they gave an email). */
	onEmailMe?: () => void;
}

/**
 * Header.
 *
 * @param props           Props.
 * @param props.ui        Theme options.
 * @param props.strings   Widget strings.
 * @param props.title     Window title.
 * @param props.titleId   ID of the title element.
 * @param props.closable  Whether the window can be closed.
 * @param props.onClose   Closes the window.
 * @param props.onNewChat Starts a new conversation.
 * @param props.closed    Outside business hours.
 * @param props.onHandoff Shows ways to reach a person.
 * @param props.onEmailMe Emails the conversation to the visitor.
 * @return Header.
 */
export function Header( {
	ui,
	strings,
	title,
	titleId,
	closable,
	onClose,
	onNewChat,
	closed = false,
	onHandoff,
	onEmailMe,
}: Props ) {
	const [ menuOpen, setMenuOpen ] = useState( false );
	const menu = useRef< HTMLDivElement >( null );
	const toggle = useRef< HTMLButtonElement >( null );
	const brand = ui.brand;
	const logo = brand.showLogo ? safeUrl( brand.logoUrl ) : '';
	const status = closed ? strings.away : brand.statusText || strings.online;

	useEffect( () => {
		if ( ! menuOpen ) {
			return undefined;
		}
		menu.current?.querySelector< HTMLButtonElement >( 'button' )?.focus();
		const onPointer = ( event: Event ) => {
			const path = event.composedPath();
			if (
				! path.includes( menu.current as EventTarget ) &&
				! path.includes( toggle.current as EventTarget )
			) {
				setMenuOpen( false );
			}
		};
		document.addEventListener( 'pointerdown', onPointer, true );
		return () =>
			document.removeEventListener( 'pointerdown', onPointer, true );
	}, [ menuOpen ] );

	const closeMenu = () => {
		setMenuOpen( false );
		toggle.current?.focus();
	};

	return (
		<header
			className={ `cl-header cl-header-${ ui.header }` }
			part="header"
		>
			{ brand.showAvatar && <Avatar brand={ brand } name={ title } /> }
			<div className="cl-heading">
				{ logo && (
					<img
						className="cl-logo"
						src={ logo }
						alt=""
						decoding="async"
					/>
				) }
				<h2 className="cl-title" id={ titleId }>
					{ title }
				</h2>
				{ ( brand.headerSubtitle || brand.showStatus ) && (
					<p className="cl-subtitle">
						{ brand.showStatus && (
							<span
								className={
									closed ? 'cl-dot cl-dot-away' : 'cl-dot'
								}
								aria-hidden="true"
							/>
						) }
						{ closed ? status : brand.headerSubtitle || status }
					</p>
				) }
			</div>
			<div className="cl-header-actions">
				<button
					ref={ toggle }
					type="button"
					className="cl-icon-button"
					aria-label={ strings.menu }
					title={ strings.menu }
					aria-haspopup="menu"
					aria-expanded={ menuOpen }
					onClick={ () => setMenuOpen( ! menuOpen ) }
				>
					<Icon name="more" />
				</button>
				{ closable && (
					<button
						type="button"
						className="cl-icon-button"
						aria-label={ strings.close }
						title={ strings.close }
						onClick={ onClose }
					>
						<Icon name="close" />
					</button>
				) }
				{ menuOpen && (
					<div
						ref={ menu }
						className="cl-menu"
						role="menu"
						tabIndex={ -1 }
						onKeyDown={ ( event ) => {
							if ( event.key === 'Escape' ) {
								event.stopPropagation();
								closeMenu();
							}
						} }
					>
						<button
							type="button"
							role="menuitem"
							onClick={ () => {
								closeMenu();
								onNewChat();
							} }
						>
							<Icon name="plus" />
							{ strings.newChat }
						</button>
						{ onHandoff && (
							<button
								type="button"
								role="menuitem"
								onClick={ () => {
									setMenuOpen( false );
									onHandoff();
								} }
							>
								<Icon name="chat" />
								{ strings.talkToPerson }
							</button>
						) }
						{ onEmailMe && (
							<button
								type="button"
								role="menuitem"
								onClick={ () => {
									closeMenu();
									onEmailMe();
								} }
							>
								<Icon name="mail" />
								{ strings.emailMe }
							</button>
						) }
						{ closable && (
							<button
								type="button"
								role="menuitem"
								onClick={ () => {
									setMenuOpen( false );
									onClose();
								} }
							>
								<Icon name="close" />
								{ strings.close }
							</button>
						) }
					</div>
				) }
			</div>
		</header>
	);
}
