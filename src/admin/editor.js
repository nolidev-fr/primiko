/**
 * Questions editor of the form screen: the business hides what it does not
 * do and renames what it says differently. The result goes into a hidden
 * field saved with the form.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useMemo, useState } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	Notice,
	Panel,
	PanelBody,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';

import {
	conditionsInOrder,
	describe,
	fieldLabel,
	getIn,
	optionLabel,
	reach,
	setIn,
	stepFields,
} from './model';

const common = { __nextHasNoMarginBottom: true };

export default function Editor( { config, output, select } ) {
	const [ templateId, setTemplateId ] = useState(
		select ? select.value : ''
	);
	const [ all, setAll ] = useState( config.customizations || {} );

	const template = config.templates[ templateId ];
	const custom = useMemo(
		() => all[ templateId ] || {},
		[ all, templateId ]
	);
	const update = ( path, value ) =>
		setAll( { ...all, [ templateId ]: setIn( custom, path, value ) } );

	useEffect( () => {
		if ( ! select ) {
			return;
		}
		const onChange = () => setTemplateId( select.value );
		select.addEventListener( 'change', onChange );
		return () => select.removeEventListener( 'change', onChange );
	}, [ select ] );

	useEffect( () => {
		output.value = JSON.stringify( { template: templateId, custom } );
	}, [ output, templateId, custom ] );

	const reachable = useMemo(
		() => ( template ? reach( template, custom ) : {} ),
		[ template, custom ]
	);

	if ( ! template ) {
		return (
			<p>
				{ __(
					'Choose a trade above to see its questions.',
					'primiko'
				) }
			</p>
		);
	}

	const hiddenCount = Object.values( reachable ).filter(
		( item ) => ! item.visible
	).length;

	return (
		<div className="primiko-editor">
			<p className="primiko-editor__intro">
				{ __(
					'Hide what you do not offer and rename what you say differently. Questions that depend on a hidden answer disappear with it. Changes apply when you update the form.',
					'primiko'
				) }
			</p>

			<Summary
				hiddenCount={ hiddenCount }
				changed={ Object.keys( custom ).length > 0 }
				onRestore={ () => setAll( { ...all, [ templateId ]: {} } ) }
			/>

			<Panel>
				{ template.steps.map( ( step, index ) => (
					<Step
						key={ step.id }
						step={ step }
						open={ 0 === index }
						template={ template }
						custom={ custom }
						reachable={ reachable }
						update={ update }
					/>
				) ) }
				<Messages
					template={ template }
					custom={ custom }
					update={ update }
				/>
			</Panel>
		</div>
	);
}

function Summary( { hiddenCount, changed, onRestore } ) {
	const [ confirming, setConfirming ] = useState( false );

	if ( ! changed ) {
		return null;
	}

	return (
		<div className="primiko-editor__summary">
			<span>
				{ hiddenCount
					? sprintf(
							/* translators: %d: number of questions visitors will not see. */
							_n(
								'%d question will not be shown to visitors.',
								'%d questions will not be shown to visitors.',
								hiddenCount,
								'primiko'
							),
							hiddenCount
					  )
					: __( 'Your changes keep every question.', 'primiko' ) }
			</span>
			{ confirming ? (
				<span className="primiko-editor__confirm">
					{ __( 'Undo every change?', 'primiko' ) }
					<Button
						variant="secondary"
						isDestructive
						size="compact"
						onClick={ () => {
							onRestore();
							setConfirming( false );
						} }
					>
						{ __( 'Restore the template', 'primiko' ) }
					</Button>
					<Button
						variant="tertiary"
						size="compact"
						onClick={ () => setConfirming( false ) }
					>
						{ __( 'Cancel', 'primiko' ) }
					</Button>
				</span>
			) : (
				<Button variant="link" onClick={ () => setConfirming( true ) }>
					{ __( 'Restore the template', 'primiko' ) }
				</Button>
			) }
		</div>
	);
}

function Step( { step, open, template, custom, reachable, update } ) {
	const hidden = !! getIn( custom, [ 'steps', step.id, 'hidden' ] );
	const title = getIn( custom, [ 'steps', step.id, 'title' ] ) || step.title;
	const fields = stepFields( step, custom );
	const off = fields.filter(
		( field ) => ! reachable[ field.id ].visible
	).length;
	const added = custom.added || [];
	let badge = '';

	if ( hidden ) {
		badge = __( 'hidden', 'primiko' );
	} else if ( off ) {
		badge = sprintf(
			/* translators: %d: number of questions not shown. */
			_n(
				'%d question not shown',
				'%d questions not shown',
				off,
				'primiko'
			),
			off
		);
	}

	// A move is allowed when every condition still reads an earlier question.
	const moved = ( index, delta ) => {
		const list = [ ...fields ];
		const target = index + delta;

		if ( target < 0 || target >= list.length ) {
			return null;
		}

		[ list[ index ], list[ target ] ] = [ list[ target ], list[ index ] ];

		return conditionsInOrder( list ) ? list : null;
	};

	const move = ( index, delta ) => {
		const list = moved( index, delta );

		if ( list ) {
			update(
				[ 'order', step.id ],
				list.map( ( field ) => field.id )
			);
		}
	};

	const addQuestion = () => {
		const id =
			'custom_' +
			Math.random().toString( 36 ).slice( 2, 8 ).padEnd( 6, '0' );

		update(
			[ 'added' ],
			[
				...added,
				{ id, step: step.id, type: 'text', label: '', required: false },
			]
		);
	};

	const changeAdded = ( id, values ) =>
		update(
			[ 'added' ],
			added.map( ( item ) =>
				item.id === id ? { ...item, ...values } : item
			)
		);

	const removeAdded = ( id ) => {
		update(
			[ 'added' ],
			added.filter( ( item ) => item.id !== id )
		);
	};

	return (
		<PanelBody
			title={ badge ? `${ title } · ${ badge }` : title }
			initialOpen={ open }
			className={ hidden ? 'is-off' : '' }
		>
			<div className="primiko-editor__step-settings">
				<ToggleControl
					{ ...common }
					label={ __( 'Show this step', 'primiko' ) }
					checked={ ! hidden }
					onChange={ ( value ) =>
						update( [ 'steps', step.id, 'hidden' ], ! value )
					}
				/>
				<TextControl
					{ ...common }
					__next40pxDefaultSize
					label={ __( 'Step title', 'primiko' ) }
					value={
						getIn( custom, [ 'steps', step.id, 'title' ] ) || ''
					}
					placeholder={ step.title }
					onChange={ ( value ) =>
						update( [ 'steps', step.id, 'title' ], value )
					}
				/>
				{ step.description && (
					<TextareaControl
						{ ...common }
						label={ __( 'Step introduction', 'primiko' ) }
						help={ __(
							'Shown under the step title. Left empty, the text of the template is used.',
							'primiko'
						) }
						value={
							getIn( custom, [
								'steps',
								step.id,
								'description',
							] ) || ''
						}
						placeholder={ step.description }
						rows={ 3 }
						onChange={ ( value ) =>
							update( [ 'steps', step.id, 'description' ], value )
						}
					/>
				) }
			</div>

			{ ! hidden &&
				fields.map( ( field, index ) => (
					<div key={ field.id } className="primiko-editor__row">
						<Mover
							label={ fieldLabel( field, custom ) }
							onUp={
								moved( index, -1 )
									? () => move( index, -1 )
									: null
							}
							onDown={
								moved( index, 1 )
									? () => move( index, 1 )
									: null
							}
						/>
						{ field.added ? (
							<AddedQuestion
								field={ field }
								onChange={ ( values ) =>
									changeAdded( field.id, values )
								}
								onRemove={ () => removeAdded( field.id ) }
							/>
						) : (
							<Question
								field={ field }
								template={ template }
								custom={ custom }
								state={ reachable[ field.id ] }
								update={ update }
							/>
						) }
					</div>
				) ) }

			{ ! hidden && (
				<Button
					variant="secondary"
					icon="plus-alt2"
					className="primiko-editor__add"
					disabled={ added.length >= 30 }
					onClick={ addQuestion }
				>
					{ __( 'Add a question to this step', 'primiko' ) }
				</Button>
			) }
		</PanelBody>
	);
}

function Mover( { label, onUp, onDown } ) {
	return (
		<div className="primiko-editor__mover">
			<Button
				icon="arrow-up-alt2"
				size="small"
				label={ sprintf(
					/* translators: %s: question. */
					__( 'Move up: %s', 'primiko' ),
					label
				) }
				disabled={ ! onUp }
				accessibleWhenDisabled
				onClick={ onUp || undefined }
			/>
			<Button
				icon="arrow-down-alt2"
				size="small"
				label={ sprintf(
					/* translators: %s: question. */
					__( 'Move down: %s', 'primiko' ),
					label
				) }
				disabled={ ! onDown }
				accessibleWhenDisabled
				onClick={ onDown || undefined }
			/>
		</div>
	);
}

/**
 * Answers of an added choice question as one line each, keeping the value
 * of an answer whose text stays, and giving new ones an unused value.
 *
 * @param {string}   text    One answer per line.
 * @param {Object[]} options Current answers.
 * @return {Object[]} Answers.
 */
function optionsFromText( text, options ) {
	const used = options.map( ( option ) => option.value );
	let next = 1;

	return text
		.split( '\n' )
		.map( ( line ) => line.trim() )
		.filter( Boolean )
		.slice( 0, 20 )
		.map( ( label ) => {
			const same = options.find( ( option ) => option.label === label );

			if ( same ) {
				return same;
			}

			while ( used.includes( 'o' + next ) ) {
				next++;
			}

			used.push( 'o' + next );
			return { value: 'o' + next, label };
		} );
}

function AddedQuestion( { field, onChange, onRemove } ) {
	const [ confirming, setConfirming ] = useState( false );
	const [ answers, setAnswers ] = useState(
		( field.options || [] ).map( ( option ) => option.label ).join( '\n' )
	);
	const types = [
		{ value: 'text', label: __( 'Short text', 'primiko' ) },
		{ value: 'textarea', label: __( 'Long text', 'primiko' ) },
		{ value: 'number', label: __( 'Number', 'primiko' ) },
		{ value: 'choice', label: __( 'Choice of answers', 'primiko' ) },
		{ value: 'yesno', label: __( 'Yes or no', 'primiko' ) },
		{ value: 'date', label: __( 'Date', 'primiko' ) },
	];

	return (
		<div className="primiko-editor__question primiko-editor__question--added">
			<div className="primiko-editor__question-head">
				<strong>
					{ field.label || __( 'New question', 'primiko' ) }
				</strong>
				<span className="primiko-editor__tag">
					{ __( 'Your question', 'primiko' ) }
				</span>
			</div>

			{ ! field.label && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Write the question, otherwise it is not saved.',
						'primiko'
					) }
				</Notice>
			) }

			<div className="primiko-editor__texts">
				<TextControl
					{ ...common }
					__next40pxDefaultSize
					label={ __( 'Question', 'primiko' ) }
					value={ field.label || '' }
					onChange={ ( value ) => onChange( { label: value } ) }
				/>
				<SelectControl
					{ ...common }
					__next40pxDefaultSize
					label={ __( 'Type of answer', 'primiko' ) }
					value={ field.type }
					options={ types }
					onChange={ ( value ) => onChange( { type: value } ) }
				/>
				{ 'choice' === field.type && (
					<>
						<TextareaControl
							{ ...common }
							label={ __( 'Answers offered', 'primiko' ) }
							help={ __( 'One answer per line.', 'primiko' ) }
							value={ answers }
							rows={ 4 }
							onChange={ ( value ) => {
								setAnswers( value );
								onChange( {
									options: optionsFromText(
										value,
										field.options || []
									),
								} );
							} }
						/>
						<ToggleControl
							{ ...common }
							label={ __( 'Several answers allowed', 'primiko' ) }
							checked={ !! field.multiple }
							onChange={ ( value ) =>
								onChange( { multiple: value } )
							}
						/>
					</>
				) }
				{ 'number' === field.type && (
					<TextControl
						{ ...common }
						__next40pxDefaultSize
						label={ __( 'Unit, if any', 'primiko' ) }
						placeholder="m²"
						value={ field.unit || '' }
						onChange={ ( value ) => onChange( { unit: value } ) }
					/>
				) }
				<TextareaControl
					{ ...common }
					label={ __( 'Help text', 'primiko' ) }
					value={ field.help || '' }
					rows={ 2 }
					onChange={ ( value ) => onChange( { help: value } ) }
				/>
				<ToggleControl
					{ ...common }
					label={ __( 'Answer required', 'primiko' ) }
					checked={ !! field.required }
					onChange={ ( value ) => onChange( { required: value } ) }
				/>
			</div>

			{ confirming ? (
				<span className="primiko-editor__confirm">
					<Button
						variant="secondary"
						isDestructive
						size="compact"
						onClick={ onRemove }
					>
						{ __( 'Remove the question', 'primiko' ) }
					</Button>
					<Button
						variant="tertiary"
						size="compact"
						onClick={ () => setConfirming( false ) }
					>
						{ __( 'Cancel', 'primiko' ) }
					</Button>
				</span>
			) : (
				<Button
					variant="link"
					isDestructive
					className="primiko-editor__edit"
					onClick={ () => setConfirming( true ) }
				>
					{ __( 'Remove', 'primiko' ) }
				</Button>
			) }
		</div>
	);
}

function Question( { field, template, custom, state, update } ) {
	const [ editing, setEditing ] = useState( false );
	const hidden = !! getIn( custom, [ 'fields', field.id, 'hidden' ] );
	const label = fieldLabel( field, custom );
	const base = [ 'fields', field.id ];

	return (
		<div
			className={
				'primiko-editor__question' + ( state.visible ? '' : ' is-off' )
			}
		>
			<div className="primiko-editor__question-head">
				<ToggleControl
					{ ...common }
					label={ label }
					checked={ ! hidden }
					onChange={ ( value ) =>
						update( [ ...base, 'hidden' ], ! value )
					}
				/>
				{ field.required && ! hidden && (
					<span className="primiko-editor__tag">
						{ __( 'Required', 'primiko' ) }
					</span>
				) }
			</div>

			{ field.show_if && (
				<p className="primiko-editor__condition">
					{ sprintf(
						/* translators: %s: condition in plain words. */
						__( 'Shown when %s.', 'primiko' ),
						describe( field.show_if, template, custom )
					) }
				</p>
			) }

			{ ! hidden && 'condition' === state.reason && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Visitors will never see this question: it depends on an answer or a question you hid.',
						'primiko'
					) }
				</Notice>
			) }

			{ ! hidden && 'options' === state.reason && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Every answer is hidden, so the question is not shown.',
						'primiko'
					) }
				</Notice>
			) }

			{ ! hidden && field.options && (
				<fieldset className="primiko-editor__options">
					<legend className="screen-reader-text">
						{ sprintf(
							/* translators: %s: question. */
							__( 'Answers offered for "%s"', 'primiko' ),
							label
						) }
					</legend>
					{ field.options.map( ( option ) => {
						const path = [ ...base, 'options', option.value ];

						return (
							<div
								key={ option.value }
								className={
									getIn( custom, [ ...path, 'hidden' ] )
										? 'primiko-editor__option is-off'
										: 'primiko-editor__option'
								}
							>
								<CheckboxControl
									{ ...common }
									label={ optionLabel(
										field,
										option.value,
										custom
									) }
									checked={
										! getIn( custom, [ ...path, 'hidden' ] )
									}
									onChange={ ( value ) =>
										update( [ ...path, 'hidden' ], ! value )
									}
								/>
								{ editing && (
									<TextControl
										{ ...common }
										__next40pxDefaultSize
										hideLabelFromVision
										label={ sprintf(
											/* translators: %s: answer. */
											__(
												'Text of the answer "%s"',
												'primiko'
											),
											option.label
										) }
										value={
											getIn( custom, [
												...path,
												'label',
											] ) || ''
										}
										placeholder={ option.label }
										onChange={ ( value ) =>
											update(
												[ ...path, 'label' ],
												value
											)
										}
									/>
								) }
							</div>
						);
					} ) }
				</fieldset>
			) }

			{ ! hidden && field.options && ! field.added && (
				<ExtraAnswers
					path={ [ ...base, 'extra' ] }
					custom={ custom }
					update={ update }
				/>
			) }

			{ ! hidden && editing && (
				<div className="primiko-editor__texts">
					<TextControl
						{ ...common }
						__next40pxDefaultSize
						label={ __( 'Question', 'primiko' ) }
						value={ getIn( custom, [ ...base, 'label' ] ) || '' }
						placeholder={ field.label }
						onChange={ ( value ) =>
							update( [ ...base, 'label' ], value )
						}
					/>
					<TextareaControl
						{ ...common }
						label={ __( 'Help text', 'primiko' ) }
						value={ getIn( custom, [ ...base, 'help' ] ) || '' }
						placeholder={
							field.help || __( 'No help text', 'primiko' )
						}
						rows={ 2 }
						onChange={ ( value ) =>
							update( [ ...base, 'help' ], value )
						}
					/>
				</div>
			) }

			{ ! hidden && (
				<Button
					variant="link"
					className="primiko-editor__edit"
					aria-expanded={ editing }
					onClick={ () => setEditing( ! editing ) }
				>
					{ editing
						? __( 'Done', 'primiko' )
						: __( 'Edit the texts', 'primiko' ) }
				</Button>
			) }
		</div>
	);
}

/**
 * Answers the business adds to a question of the template. Each one keeps
 * a fixed value (custom_ and six characters), so saved requests stay
 * readable when its text changes.
 *
 * @param {Object}   props        Props.
 * @param {string[]} props.path   Path of the list in the customizations.
 * @param {Object}   props.custom Customizations.
 * @param {Function} props.update Updates the customizations.
 */
function ExtraAnswers( { path, custom, update } ) {
	const extra = getIn( custom, path ) || [];

	const change = ( value, label ) =>
		update(
			path,
			extra.map( ( item ) =>
				item.value === value ? { ...item, label } : item
			)
		);

	const add = () =>
		update( path, [
			...extra,
			{
				value:
					'custom_' +
					Math.random().toString( 36 ).slice( 2, 8 ).padEnd( 6, '0' ),
				label: '',
			},
		] );

	return (
		<div className="primiko-editor__extra">
			{ extra.map( ( item ) => (
				<div key={ item.value } className="primiko-editor__extra-row">
					<TextControl
						{ ...common }
						__next40pxDefaultSize
						label={ __( 'Added answer', 'primiko' ) }
						value={ item.label }
						onChange={ ( value ) => change( item.value, value ) }
					/>
					<Button
						variant="link"
						isDestructive
						onClick={ () =>
							update(
								path,
								extra.filter(
									( other ) => other.value !== item.value
								)
							)
						}
					>
						{ __( 'Remove', 'primiko' ) }
					</Button>
				</div>
			) ) }
			{ extra.length < 20 && (
				<Button variant="secondary" size="compact" onClick={ add }>
					{ __( 'Add an answer', 'primiko' ) }
				</Button>
			) }
		</div>
	);
}

function Messages( { template, custom, update } ) {
	const fields = [
		[
			'success',
			__( 'Message shown once the request is sent', 'primiko' ),
			TextareaControl,
		],
		[
			'success_no_copy',
			__(
				'Message shown when no copy is sent (no email address, or too many copies to that address today)',
				'primiko'
			),
			TextareaControl,
		],
		[
			'privacy_notice',
			__(
				'Privacy notice under the consent box (what the answers are used for, how long they are kept)',
				'primiko'
			),
			TextareaControl,
		],
		[
			'fallback',
			__(
				'Shown when the request cannot be sent (too many requests from one connection, server error): a good place for your phone number',
				'primiko'
			),
			TextareaControl,
		],
		[
			'acknowledgement_subject',
			__( 'Subject of the copy sent to the visitor', 'primiko' ),
			TextControl,
		],
		[
			'acknowledgement_body',
			__( 'Beginning of that email (the answers follow)', 'primiko' ),
			TextareaControl,
		],
	];

	return (
		<PanelBody
			title={ __( 'Messages to the visitor', 'primiko' ) }
			initialOpen={ false }
		>
			<p className="primiko-editor__condition">
				{ __(
					'Write {name}, {email}, {site_name} or {reference} where the name of the visitor, their email, the name of your site or the number of the request should appear, and {retention} for how long requests are kept (privacy notice). Leave a field empty to keep the template text.',
					'primiko'
				) }
			</p>
			{ fields.map( ( [ key, label, Control ] ) => (
				<div key={ key } className="primiko-editor__message">
					<Control
						{ ...common }
						{ ...( TextControl === Control
							? { __next40pxDefaultSize: true }
							: {
									rows:
										{
											acknowledgement_body: 5,
											privacy_notice: 4,
										}[ key ] || 2,
							  } ) }
						label={ label }
						value={ getIn( custom, [ 'messages', key ] ) || '' }
						placeholder={ template.messages[ key ] }
						onChange={ ( value ) =>
							update( [ 'messages', key ], value )
						}
					/>
				</div>
			) ) }
		</PanelBody>
	);
}
