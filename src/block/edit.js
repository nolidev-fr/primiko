/**
 * Editor view of the block: a choice of form. The form itself only works on
 * the published page, so the editor shows a placeholder instead.
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	InspectorControls,
	PanelColorSettings,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	PanelBody,
	Placeholder,
	RangeControl,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';

export default function Edit( { attributes, setAttributes } ) {
	const { formId, accentColor, radius, headingLevel } = attributes;
	const [ forms, setForms ] = useState( null );
	const [ failed, setFailed ] = useState( false );

	useEffect( () => {
		apiFetch( { path: '/primiko/v1/forms' } )
			.then( setForms )
			.catch( () => {
				setFailed( true );
				setForms( [] );
			} );
	}, [] );

	const options = [
		{ value: 0, label: __( 'Choose a form', 'primiko' ) },
		...( forms || [] ).map( ( form ) => ( {
			value: form.id,
			label:
				form.title ||
				sprintf(
					/* translators: %d: form ID. */
					__( 'Form %d', 'primiko' ),
					form.id
				),
		} ) ),
	];
	const current = ( forms || [] ).find( ( form ) => form.id === formId );

	const picker = (
		<SelectControl
			label={ __( 'Quote form', 'primiko' ) }
			value={ formId }
			options={ options }
			onChange={ ( value ) =>
				setAttributes( { formId: parseInt( value, 10 ) || 0 } )
			}
			__nextHasNoMarginBottom
			__next40pxDefaultSize
		/>
	);

	let content;

	if ( failed ) {
		content = (
			<p>
				{ __(
					'The quote forms could not be loaded. Listing them needs the right to edit pages: ask an editor or an administrator.',
					'primiko'
				) }
			</p>
		);
	} else if ( null === forms ) {
		content = <Spinner />;
	} else if ( ! forms.length ) {
		content = (
			<p>
				{ __(
					'Create a form first, under Quote requests, Forms.',
					'primiko'
				) }
			</p>
		);
	} else if ( current ) {
		content = (
			<p>
				{ sprintf(
					/* translators: %s: form title. */
					__(
						'%s. The form is shown on the published page.',
						'primiko'
					),
					current.title
				) }
			</p>
		);
	} else {
		content = picker;
	}

	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'Form', 'primiko' ) }>
					{ picker }
					<SelectControl
						label={ __( 'Step titles', 'primiko' ) }
						value={ headingLevel }
						options={ [ 2, 3, 4 ].map( ( level ) => ( {
							value: level,
							label: sprintf(
								/* translators: %d: heading level, from 2 to 4. */
								__( 'Heading %d', 'primiko' ),
								level
							),
						} ) ) }
						onChange={ ( value ) =>
							setAttributes( {
								headingLevel: parseInt( value, 10 ) || 2,
							} )
						}
						help={ __(
							'Choose Heading 3 when the form sits under a section title of level 2.',
							'primiko'
						) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</PanelBody>
				<PanelColorSettings
					title={ __( 'Main color', 'primiko' ) }
					colorSettings={ [
						{
							value: accentColor,
							onChange: ( value ) =>
								setAttributes( { accentColor: value } ),
							label: __(
								'Selected answers, progress and focus',
								'primiko'
							),
						},
					] }
				>
					<p>
						{ __(
							'Left empty, the form takes the main color of your theme.',
							'primiko'
						) }
					</p>
				</PanelColorSettings>
				<PanelBody
					title={ __( 'Corners', 'primiko' ) }
					initialOpen={ false }
				>
					<RangeControl
						label={ __(
							'Corner radius of the answers',
							'primiko'
						) }
						value={ radius }
						onChange={ ( value ) =>
							setAttributes( { radius: value } )
						}
						min={ 0 }
						max={ 24 }
						allowReset
						help={ __(
							'Left empty, the corners follow the form default.',
							'primiko'
						) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</PanelBody>
			</InspectorControls>
			<Placeholder
				icon="clipboard"
				label={ __( 'Quote request form', 'primiko' ) }
			>
				{ content }
			</Placeholder>
		</div>
	);
}
