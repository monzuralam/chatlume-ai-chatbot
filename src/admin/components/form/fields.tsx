/**
 * Form building blocks shared by the settings pages: a section card, a
 * switch row, and a number field, all wired to react-hook-form.
 */
import { useId, type ReactNode } from 'react';
import {
	Controller,
	type Control,
	type FieldPath,
	type FieldValues,
	type UseFormRegister,
} from 'react-hook-form';

import {
	Card,
	CardContent,
	CardDescription,
	CardHeader,
	CardTitle,
} from '../ui/card';
import { Field } from '../ui/field';
import { Input } from '../ui/input';
import { Switch } from '../ui/switch';

/**
 * A titled settings card.
 *
 * @param props             Props.
 * @param props.title       Title.
 * @param props.description Explanation.
 * @param props.actions     Buttons beside the title.
 * @param props.children    Fields.
 */
export function FormSection( {
	title,
	description,
	actions,
	children,
}: {
	title: string;
	description?: ReactNode;
	actions?: ReactNode;
	children: ReactNode;
} ) {
	return (
		<Card>
			<CardHeader className="chatlume:flex-row chatlume:items-start chatlume:justify-between chatlume:gap-4">
				<div>
					<CardTitle>{ title }</CardTitle>
					{ description && (
						<CardDescription>{ description }</CardDescription>
					) }
				</div>
				{ actions }
			</CardHeader>
			<CardContent className="chatlume:flex chatlume:flex-col chatlume:gap-5">
				{ children }
			</CardContent>
		</Card>
	);
}

/**
 * A labelled switch with an explanation.
 *
 * @param props             Props.
 * @param props.control     Form control.
 * @param props.name        Field name.
 * @param props.label       Label.
 * @param props.description Explanation.
 */
export function SwitchField< T extends FieldValues >( {
	control,
	name,
	label,
	description,
}: {
	control: Control< T >;
	name: FieldPath< T >;
	label: string;
	description: string;
} ) {
	const id = useId();
	return (
		<div className="chatlume:flex chatlume:items-start chatlume:justify-between chatlume:gap-6">
			<div>
				<label
					htmlFor={ id }
					className="chatlume:text-sm chatlume:font-medium chatlume:text-fg"
				>
					{ label }
				</label>
				<p
					id={ `${ id }-hint` }
					className="chatlume:mt-1 chatlume:max-w-xl chatlume:text-[13px] chatlume:text-fg-muted"
				>
					{ description }
				</p>
			</div>
			<Controller
				control={ control }
				name={ name }
				render={ ( { field } ) => (
					<Switch
						id={ id }
						aria-describedby={ `${ id }-hint` }
						checked={ !! field.value }
						onCheckedChange={ field.onChange }
						onBlur={ field.onBlur }
					/>
				) }
			/>
		</div>
	);
}

/**
 * A number input with label, hint, tooltip, unit, and inline error.
 *
 * @param props          Props.
 * @param props.register Form register function.
 * @param props.name     Field name.
 * @param props.label    Label.
 * @param props.hint     Help text.
 * @param props.tooltip  Extra explanation.
 * @param props.min      Minimum.
 * @param props.max      Maximum.
 * @param props.step     Step.
 * @param props.error    Validation message.
 * @param props.suffix   Unit after the input.
 */
export function NumberField< T extends FieldValues >( {
	register,
	name,
	label,
	hint,
	tooltip,
	min,
	max,
	step = 1,
	error,
	suffix,
}: {
	register: UseFormRegister< T >;
	name: FieldPath< T >;
	label: string;
	hint?: string;
	tooltip?: string;
	min: number;
	max: number;
	step?: number;
	error?: string;
	suffix?: string;
} ) {
	const id = useId();
	return (
		<Field
			id={ id }
			label={ label }
			hint={ hint }
			tooltip={ tooltip }
			error={ error }
		>
			<div className="chatlume:flex chatlume:items-center chatlume:gap-2">
				<Input
					id={ id }
					type="number"
					inputMode={ step < 1 ? 'decimal' : 'numeric' }
					min={ min }
					max={ max }
					step={ step }
					className="chatlume:w-28"
					aria-invalid={ error ? true : undefined }
					aria-describedby={ `${ id }-hint ${ id }-error` }
					{ ...register( name, { valueAsNumber: true } ) }
				/>
				{ suffix && (
					<span className="chatlume:text-sm chatlume:text-fg-muted">
						{ suffix }
					</span>
				) }
			</div>
		</Field>
	);
}
