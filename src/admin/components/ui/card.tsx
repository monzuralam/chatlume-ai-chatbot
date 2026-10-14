import type { HTMLAttributes } from 'react';

import { cn } from '../../lib/utils';

export function Card( {
	className,
	...props
}: HTMLAttributes< HTMLDivElement > ) {
	return (
		<div
			className={ cn(
				'chatlume:rounded-card chatlume:border chatlume:border-border chatlume:bg-surface chatlume:shadow-card',
				className
			) }
			{ ...props }
		/>
	);
}

export function CardHeader( {
	className,
	...props
}: HTMLAttributes< HTMLDivElement > ) {
	return (
		<div
			className={ cn(
				'chatlume:flex chatlume:flex-col chatlume:gap-1 chatlume:px-6 chatlume:pt-5 chatlume:pb-4',
				className
			) }
			{ ...props }
		/>
	);
}

export function CardTitle( {
	className,
	...props
}: HTMLAttributes< HTMLHeadingElement > ) {
	return (
		<h2
			className={ cn(
				'chatlume:text-[15px] chatlume:font-semibold chatlume:tracking-tight chatlume:text-fg',
				className
			) }
			{ ...props }
		/>
	);
}

export function CardDescription( {
	className,
	...props
}: HTMLAttributes< HTMLParagraphElement > ) {
	return (
		<p
			className={ cn(
				'chatlume:text-sm chatlume:text-fg-muted',
				className
			) }
			{ ...props }
		/>
	);
}

export function CardContent( {
	className,
	...props
}: HTMLAttributes< HTMLDivElement > ) {
	return (
		<div
			className={ cn( 'chatlume:px-6 chatlume:pb-6', className ) }
			{ ...props }
		/>
	);
}
