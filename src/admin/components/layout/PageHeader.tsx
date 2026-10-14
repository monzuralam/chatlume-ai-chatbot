import type { ReactNode } from 'react';

interface PageHeaderProps {
	title: string;
	description?: ReactNode;
	actions?: ReactNode;
}

/**
 * Page title row with optional description and actions.
 *
 * @param props             Props.
 * @param props.title       Page title.
 * @param props.description One-line explanation.
 * @param props.actions     Buttons on the right.
 */
export function PageHeader( { title, description, actions }: PageHeaderProps ) {
	return (
		<div className="chatlume:mb-8 chatlume:flex chatlume:flex-wrap chatlume:items-end chatlume:justify-between chatlume:gap-4">
			<div className="chatlume:min-w-0">
				<h1 className="chatlume:text-2xl chatlume:font-semibold chatlume:tracking-tight chatlume:text-fg">
					{ title }
				</h1>
				{ description && (
					<p className="chatlume:mt-1.5 chatlume:max-w-2xl chatlume:text-[15px] chatlume:text-fg-muted">
						{ description }
					</p>
				) }
			</div>
			{ actions && (
				<div className="chatlume:flex chatlume:items-center chatlume:gap-2">
					{ actions }
				</div>
			) }
		</div>
	);
}
