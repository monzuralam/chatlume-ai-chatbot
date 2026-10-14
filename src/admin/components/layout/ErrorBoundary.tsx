import { __ } from '@wordpress/i18n';
import { Component, type ErrorInfo, type ReactNode } from 'react';

import { Button } from '../ui/button';
import { EmptyState } from './EmptyState';

interface ErrorBoundaryProps {
	children: ReactNode;
	/** Changing this value (e.g. the route path) clears the error. */
	resetKey?: string;
}

interface ErrorBoundaryState {
	error: Error | null;
}

/**
 * Keeps one failing page (including add-on pages) from breaking the whole
 * app, and lets the admin retry.
 */
export class ErrorBoundary extends Component<
	ErrorBoundaryProps,
	ErrorBoundaryState
> {
	state: ErrorBoundaryState = { error: null };

	static getDerivedStateFromError( error: Error ): ErrorBoundaryState {
		return { error };
	}

	componentDidCatch( error: Error, info: ErrorInfo ): void {
		// Keep the details available to developers in the browser console.
		// eslint-disable-next-line no-console
		console.error( 'Chatlume:', error, info.componentStack );
	}

	componentDidUpdate( previous: ErrorBoundaryProps ): void {
		if ( previous.resetKey !== this.props.resetKey && this.state.error ) {
			this.setState( { error: null } );
		}
	}

	render() {
		if ( ! this.state.error ) {
			return this.props.children;
		}

		return (
			<EmptyState
				title={ __(
					'This page could not be displayed',
					'chatlume-ai-chatbot'
				) }
				description={ __(
					'Something went wrong while loading this page. Try again, and if it keeps happening, reload the browser tab.',
					'chatlume-ai-chatbot'
				) }
				action={
					<Button
						variant="primary"
						onClick={ () => this.setState( { error: null } ) }
					>
						{ __( 'Try again', 'chatlume-ai-chatbot' ) }
					</Button>
				}
			/>
		);
	}
}
