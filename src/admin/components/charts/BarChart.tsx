/**
 * A small, dependency-free bar chart (SVG) in the admin's theme colors,
 * with a visually hidden table of the same numbers for screen readers.
 */
import { useId, useState } from 'react';

export interface BarDatum {
	key: string;
	/** Short label under the bar. */
	label: string;
	/** Full label for the tooltip and table. */
	title: string;
	value: number;
	/** Extra line shown in the tooltip. */
	detail?: string;
}

interface BarChartProps {
	data: BarDatum[];
	/** Describes the chart for screen readers. */
	label: string;
	/** Column headings of the hidden table. */
	columns: [ string, string ];
	height?: number;
}

const WIDTH = 640;
const PAD_TOP = 12;
const PAD_BOTTOM = 26;
const PAD_LEFT = 32;

/**
 * Round axis maximum for a value (1, 2, 5, 10, 20, 50…).
 *
 * @param value Largest value.
 * @return Axis maximum.
 */
export function niceMax( value: number ): number {
	if ( value <= 0 ) {
		return 4;
	}
	const magnitude = 10 ** Math.floor( Math.log10( value ) );
	for ( const step of [ 1, 2, 5, 10 ] ) {
		if ( value <= step * magnitude ) {
			return step * magnitude;
		}
	}
	return 10 * magnitude;
}

/**
 * Bar chart.
 *
 * @param props         Props.
 * @param props.data    Bars.
 * @param props.label   Accessible description.
 * @param props.columns Hidden table headings.
 * @param props.height  Height in pixels.
 */
export function BarChart( {
	data,
	label,
	columns,
	height = 220,
}: BarChartProps ) {
	const [ active, setActive ] = useState< number | null >( null );
	const titleId = useId();
	const max = niceMax(
		Math.max( 0, ...data.map( ( datum ) => datum.value ) )
	);
	const plotHeight = height - PAD_TOP - PAD_BOTTOM;
	const plotWidth = WIDTH - PAD_LEFT;
	const slot = plotWidth / Math.max( 1, data.length );
	const barWidth = Math.max( 4, Math.min( 36, slot * 0.6 ) );
	const labelEvery = Math.ceil( data.length / 10 );
	const ticks = [ 0, max / 2, max ];
	const shown = active !== null ? data[ active ] : null;

	return (
		<div className="chatlume:relative">
			<svg
				viewBox={ `0 0 ${ WIDTH } ${ height }` }
				className="chatlume:h-auto chatlume:w-full"
				role="img"
				aria-labelledby={ titleId }
				onMouseLeave={ () => setActive( null ) }
			>
				<title id={ titleId }>{ label }</title>
				{ ticks.map( ( tick ) => {
					const y =
						PAD_TOP + plotHeight - ( tick / max ) * plotHeight;
					return (
						<g key={ tick }>
							<line
								x1={ PAD_LEFT }
								x2={ WIDTH }
								y1={ y }
								y2={ y }
								stroke="var(--chatlume-ui-border)"
								strokeDasharray={
									tick === 0 ? undefined : '3 4'
								}
							/>
							<text
								x={ PAD_LEFT - 6 }
								y={ y + 4 }
								textAnchor="end"
								fontSize="11"
								fill="var(--chatlume-ui-fg-muted)"
							>
								{ Number.isInteger( tick )
									? tick
									: tick.toFixed( 1 ) }
							</text>
						</g>
					);
				} ) }
				{ data.map( ( datum, index ) => {
					const barHeight = ( datum.value / max ) * plotHeight;
					const x = PAD_LEFT + index * slot + ( slot - barWidth ) / 2;
					return (
						<g
							key={ datum.key }
							onMouseEnter={ () => setActive( index ) }
						>
							<rect
								x={ PAD_LEFT + index * slot }
								y={ PAD_TOP }
								width={ slot }
								height={ plotHeight }
								fill="transparent"
							/>
							<rect
								x={ x }
								y={ PAD_TOP + plotHeight - barHeight }
								width={ barWidth }
								height={ Math.max(
									datum.value > 0 ? 2 : 0,
									barHeight
								) }
								rx={ Math.min( 4, barWidth / 3 ) }
								fill="var(--chatlume-ui-accent)"
								opacity={
									active === null || active === index
										? 1
										: 0.45
								}
							/>
							{ index % labelEvery === 0 && (
								<text
									x={ PAD_LEFT + index * slot + slot / 2 }
									y={ height - 8 }
									textAnchor="middle"
									fontSize="11"
									fill="var(--chatlume-ui-fg-muted)"
								>
									{ datum.label }
								</text>
							) }
						</g>
					);
				} ) }
			</svg>
			{ shown && (
				<div
					aria-hidden="true"
					className="chatlume:pointer-events-none chatlume:absolute chatlume:top-0 chatlume:rounded-control chatlume:border chatlume:border-border chatlume:bg-surface chatlume:px-2.5 chatlume:py-1.5 chatlume:text-xs chatlume:shadow-float"
					style={ {
						left: `${ ( ( PAD_LEFT + ( active ?? 0 ) * slot + slot / 2 ) / WIDTH ) * 100 }%`,
						transform: 'translateX(-50%)',
					} }
				>
					<div className="chatlume:font-medium chatlume:text-fg">
						{ shown.title }
					</div>
					<div className="chatlume:text-fg-muted">
						{ shown.detail }
					</div>
				</div>
			) }
			<table className="chatlume:sr-only">
				<caption>{ label }</caption>
				<thead>
					<tr>
						<th scope="col">{ columns[ 0 ] }</th>
						<th scope="col">{ columns[ 1 ] }</th>
					</tr>
				</thead>
				<tbody>
					{ data.map( ( datum ) => (
						<tr key={ datum.key }>
							<td>{ datum.title }</td>
							<td>{ datum.detail ?? datum.value }</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}
