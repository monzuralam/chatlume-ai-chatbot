import type { ReactNode } from 'react';

interface EmptyStateProps {
	title: string;
	description: string;
	action?: ReactNode;
	illustration?: ReactNode;
}

/**
 * Default illustration: a chat bubble drifting over a dotted horizon.
 */
function DefaultIllustration() {
	return (
		<svg
			viewBox="0 0 160 120"
			className="chatlume:h-28 chatlume:w-auto"
			aria-hidden="true"
		>
			<defs>
				<pattern
					id="chatlume-empty-dots"
					width="10"
					height="10"
					patternUnits="userSpaceOnUse"
				>
					<circle
						cx="2"
						cy="2"
						r="1.2"
						fill="var(--chatlume-ui-border)"
					/>
				</pattern>
			</defs>
			<rect
				x="10"
				y="78"
				width="140"
				height="34"
				rx="6"
				fill="url(#chatlume-empty-dots)"
			/>
			<path
				d="M48 22h64a14 14 0 0 1 14 14v26a14 14 0 0 1-14 14H76l-16 13v-13H48a14 14 0 0 1-14-14V36a14 14 0 0 1 14-14Z"
				fill="var(--chatlume-ui-accent-soft)"
				stroke="var(--chatlume-ui-accent)"
				strokeWidth="2"
			/>
			<circle cx="64" cy="49" r="4" fill="var(--chatlume-ui-accent)" />
			<circle
				cx="80"
				cy="49"
				r="4"
				fill="var(--chatlume-ui-accent)"
				opacity="0.7"
			/>
			<circle
				cx="96"
				cy="49"
				r="4"
				fill="var(--chatlume-ui-accent)"
				opacity="0.4"
			/>
			<path
				d="M128 14l2 5 5 2-5 2-2 5-2-5-5-2 5-2 2-5Z"
				fill="var(--chatlume-ui-warning)"
			/>
		</svg>
	);
}

/**
 * Empty state: illustration, one-line explanation, and a clear call to action.
 *
 * @param props              Props.
 * @param props.title        Heading.
 * @param props.description  One-line explanation.
 * @param props.action       Call to action.
 * @param props.illustration Custom illustration.
 */
export function EmptyState( {
	title,
	description,
	action,
	illustration,
}: EmptyStateProps ) {
	return (
		<div className="chatlume:flex chatlume:flex-col chatlume:items-center chatlume:px-6 chatlume:py-16 chatlume:text-center">
			{ illustration ?? <DefaultIllustration /> }
			<h2 className="chatlume:mt-6 chatlume:text-lg chatlume:font-semibold chatlume:text-fg">
				{ title }
			</h2>
			<p className="chatlume:mt-2 chatlume:max-w-md chatlume:text-sm chatlume:text-fg-muted">
				{ description }
			</p>
			{ action && <div className="chatlume:mt-6">{ action }</div> }
		</div>
	);
}
