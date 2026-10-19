/**
 * Trade picker of the form screen: a search over the title, description,
 * keywords and category of every template, results grouped by category.
 *
 * It drives the plain <select> printed by the server, which stays the field
 * that is saved (and the fallback without JavaScript), and tells the
 * questions editor through its change event.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useMemo, useState } from '@wordpress/element';
import { Button, SearchControl } from '@wordpress/components';

/**
 * Lowercase text without accents, for matching "vitrier" with "Vitrier"
 * or "metre" with "mètre".
 *
 * @param {string} text Text.
 * @return {string} Normalized text.
 */
function normalize( text ) {
	return ( text || '' )
		.toLowerCase()
		.normalize( 'NFD' )
		.replace( /[̀-ͯ]/g, '' );
}

export default function Picker( { config, select } ) {
	const [ current, setCurrent ] = useState( select.value );
	const [ open, setOpen ] = useState( ! select.value );
	const [ search, setSearch ] = useState( '' );

	const index = useMemo(
		() =>
			config.templates.map( ( template ) => ( {
				...template,
				// Every word of the texts searched, so that a search matches the
				// start of a word: "toit" finds "toiture", "menage" does not
				// find "amenagement".
				words: normalize(
					[
						template.title,
						template.description,
						template.keywords,
						config.categories[ template.category ],
					].join( ' ' )
				).split( /[^a-z0-9]+/ ),
			} ) ),
		[ config ]
	);

	const terms = normalize( search )
		.split( /[^a-z0-9]+/ )
		.filter( Boolean );
	const results = index.filter( ( template ) =>
		terms.every( ( term ) =>
			template.words.some( ( word ) => word.startsWith( term ) )
		)
	);
	const chosen = index.find( ( template ) => template.id === current );

	const choose = ( id ) => {
		select.value = id;
		select.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );
		setCurrent( id );
		setOpen( false );
		setSearch( '' );
	};

	if ( ! open && chosen ) {
		return (
			<div className="primiko-picker__current">
				<div>
					<strong>{ chosen.title }</strong>
					<p className="description">{ chosen.description }</p>
				</div>
				<Button variant="secondary" onClick={ () => setOpen( true ) }>
					{ __( 'Change trade', 'primiko' ) }
				</Button>
			</div>
		);
	}

	return (
		<div className="primiko-picker">
			<SearchControl
				__nextHasNoMarginBottom
				label={ __( 'Search a trade', 'primiko' ) }
				placeholder={ __(
					'Roofer, plumber, notary, cleaning…',
					'primiko'
				) }
				value={ search }
				onChange={ setSearch }
			/>

			<p className="primiko-picker__count" aria-live="polite">
				{ sprintf(
					/* translators: %d: number of templates found. */
					_n(
						'%d template',
						'%d templates',
						results.length,
						'primiko'
					),
					results.length
				) }
			</p>

			{ ! results.length && (
				<div className="primiko-picker__empty">
					<p>
						{ __(
							'No template matches. The general templates fit any trade, and you can adapt their questions:',
							'primiko'
						) }
					</p>
					{ index
						.filter(
							( template ) => 'general' === template.category
						)
						.map( ( template ) => (
							<Button
								key={ template.id }
								variant="secondary"
								onClick={ () => choose( template.id ) }
							>
								{ template.title }
							</Button>
						) ) }
				</div>
			) }

			{ Object.keys( config.categories ).map( ( category ) => {
				const items = results.filter(
					( template ) => template.category === category
				);

				if ( ! items.length ) {
					return null;
				}

				return (
					<div key={ category } className="primiko-picker__group">
						<h3 className="primiko-picker__heading">
							{ config.categories[ category ] }
						</h3>
						<ul className="primiko-picker__list">
							{ items.map( ( template ) => (
								<li key={ template.id }>
									<button
										type="button"
										className={
											'primiko-picker__item' +
											( template.id === current
												? ' is-current'
												: '' )
										}
										aria-pressed={ template.id === current }
										onClick={ () => choose( template.id ) }
									>
										<span className="primiko-picker__title">
											{ template.title }
										</span>
										<span className="primiko-picker__description">
											{ template.description }
										</span>
									</button>
								</li>
							) ) }
						</ul>
					</div>
				);
			} ) }

			{ chosen && (
				<Button variant="tertiary" onClick={ () => setOpen( false ) }>
					{ __( 'Keep the current trade', 'primiko' ) }
				</Button>
			) }
		</div>
	);
}
