/**
 * What the questions editor knows about a template and the changes of the
 * business: effective texts, what is hidden, and which questions can no
 * longer appear because they depend on something hidden.
 *
 * The saved shape matches includes/class-customizations.php.
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Returns a copy of the customizations with one value set, removing empty
 * branches so that only real changes are kept.
 *
 * @param {Object}   custom Customizations of one template.
 * @param {string[]} path   Keys down to the value.
 * @param {*}        value  New value. Empty string or false removes it.
 * @return {Object} New customizations.
 */
export function setIn( custom, path, value ) {
	const copy = structuredClone( custom || {} );
	let node = copy;

	path.slice( 0, -1 ).forEach( ( key ) => {
		if ( ! node[ key ] || 'object' !== typeof node[ key ] ) {
			node[ key ] = {};
		}
		node = node[ key ];
	} );

	const last = path[ path.length - 1 ];

	if ( '' === value || false === value || undefined === value ) {
		delete node[ last ];
	} else {
		node[ last ] = value;
	}

	return prune( copy );
}

/**
 * Removes empty objects, deepest first.
 *
 * @param {Object} object Object to clean.
 * @return {Object} The same object.
 */
function prune( object ) {
	Object.keys( object ).forEach( ( key ) => {
		if ( object[ key ] && 'object' === typeof object[ key ] ) {
			prune( object[ key ] );

			if ( ! Object.keys( object[ key ] ).length ) {
				delete object[ key ];
			}
		}
	} );

	return object;
}

/**
 * Reads a value of the customizations.
 *
 * @param {Object}   custom Customizations of one template.
 * @param {string[]} path   Keys down to the value.
 * @return {*} Value, or undefined.
 */
export function getIn( custom, path ) {
	return path.reduce(
		( node, key ) =>
			node && 'object' === typeof node ? node[ key ] : undefined,
		custom
	);
}

/**
 * Identifiers of the questions a condition reads.
 *
 * @param {Object|undefined} condition Condition.
 * @return {string[]} Question identifiers.
 */
function conditionFields( condition ) {
	if ( ! condition ) {
		return [];
	}

	return [
		...( condition.all || [] ).flatMap( conditionFields ),
		...( condition.any || [] ).flatMap( conditionFields ),
		...( condition.field ? [ condition.field ] : [] ),
	];
}

/**
 * Tells whether every condition of a list of questions reads only questions
 * placed before it. Mirrors Customizations::conditions_in_order().
 *
 * @param {Object[]} fields Questions of one step, in order.
 * @return {boolean} Whether the order keeps the conditions working.
 */
export function conditionsInOrder( fields ) {
	const ids = fields.map( ( field ) => field.id );
	const seen = [];

	return fields.every( ( field ) => {
		const ok = conditionFields( field.show_if ).every(
			( read ) => ! ids.includes( read ) || seen.includes( read )
		);

		seen.push( field.id );
		return ok;
	} );
}

/**
 * Questions of a step: the template ones, then those the business added,
 * in its chosen order when that order keeps the conditions working.
 * Mirrors Customizations::step_fields().
 *
 * @param {Object} step   Step of the template.
 * @param {Object} custom Customizations.
 * @return {Object[]} Questions, added ones marked with added: true.
 */
export function stepFields( step, custom ) {
	const fields = [
		...step.fields,
		...( ( custom && custom.added ) || [] )
			.filter( ( added ) => added.step === step.id )
			.map( ( added ) => ( { ...added, added: true } ) ),
	];
	const order = getIn( custom, [ 'order', step.id ] ) || [];

	if ( ! order.length ) {
		return fields;
	}

	const rank = ( field, index ) =>
		order.includes( field.id )
			? order.indexOf( field.id )
			: order.length + index;
	const sorted = fields
		.map( ( field, index ) => ( { field, key: rank( field, index ) } ) )
		.sort( ( a, b ) => a.key - b.key )
		.map( ( item ) => item.field );

	return conditionsInOrder( sorted ) ? sorted : fields;
}

/**
 * Works out, in form order, which steps, questions and answers a visitor
 * can still see, given what the business hid. A question is out of reach
 * when it is hidden, when its step is, or when its condition can no longer
 * be met (it depends on a hidden question or answer). Mirrors the way the
 * form and the server treat hidden questions: as never answered.
 *
 * @param {Object} template Translated template.
 * @param {Object} custom   Customizations of the template.
 * @return {Object} reach.fields[id] = { visible, reason, options: [visible values] }
 */
export function reach( template, custom ) {
	const fields = {};

	template.steps.forEach( ( step ) => {
		const stepHidden = !! getIn( custom, [ 'steps', step.id, 'hidden' ] );
		const stepReachable = ! stepHidden && possible( step.show_if, fields );

		stepFields( step, custom ).forEach( ( field ) => {
			const hidden = !! getIn( custom, [ 'fields', field.id, 'hidden' ] );
			const options = ( field.options || [] )
				.filter(
					( option ) =>
						! getIn( custom, [
							'fields',
							field.id,
							'options',
							option.value,
							'hidden',
						] )
				)
				.map( ( option ) => option.value );
			const noOptions = !! field.options && ! options.length;
			let reason = '';

			if ( stepHidden ) {
				reason = 'step';
			} else if ( hidden ) {
				reason = 'hidden';
			} else if ( noOptions ) {
				reason = 'options';
			} else if (
				! stepReachable ||
				! possible( field.show_if, fields )
			) {
				reason = 'condition';
			}

			fields[ field.id ] = {
				visible: '' === reason,
				reason,
				options: 'yesno' === field.type ? [ 'yes', 'no' ] : options,
				field,
			};
		} );
	} );

	return fields;
}

/**
 * Tells whether a condition can still be met by some visitor.
 *
 * @param {Object|undefined} condition Condition.
 * @param {Object}           fields    Reach of the questions before it.
 * @return {boolean} Whether it can be met.
 */
function possible( condition, fields ) {
	if ( ! condition ) {
		return true;
	}

	if ( condition.all ) {
		return condition.all.every( ( inner ) => possible( inner, fields ) );
	}

	if ( condition.any ) {
		return condition.any.some( ( inner ) => possible( inner, fields ) );
	}

	const target = fields[ condition.field ];
	const visible = !! target && target.visible;
	const values = visible ? target.options : [];
	const choice = visible && target.field.options;

	if ( undefined !== condition.equals ) {
		return (
			visible &&
			( ! choice || values.includes( String( condition.equals ) ) )
		);
	}

	if ( condition.in ) {
		return (
			visible &&
			( ! choice ||
				condition.in.some( ( value ) =>
					values.includes( String( value ) )
				) )
		);
	}

	if ( condition.not_in ) {
		// Also met when the question is left unanswered or out of reach.
		return true;
	}

	if ( undefined !== condition.filled ) {
		return condition.filled ? visible : true;
	}

	return true;
}

/**
 * Describes a condition in plain words, with the effective texts.
 *
 * @param {Object} condition Condition.
 * @param {Object} template  Translated template.
 * @param {Object} custom    Customizations.
 * @return {string} Description.
 */
export function describe( condition, template, custom ) {
	/* translators: joins conditions or answers of which one is enough. */
	const or = ' ' + __( 'or', 'primiko' ) + ' ';

	if ( condition.all ) {
		/* translators: joins conditions that must all be met. */
		const and = ' ' + __( 'and', 'primiko' ) + ' ';

		return condition.all
			.map( ( inner ) => describe( inner, template, custom ) )
			.join( and );
	}

	if ( condition.any ) {
		return condition.any
			.map( ( inner ) => describe( inner, template, custom ) )
			.join( or );
	}

	const field = findField( template, condition.field, custom );
	const question = field ? fieldLabel( field, custom ) : condition.field;
	const answer = ( value ) =>
		sprintf(
			/* translators: %s: an answer, quoted in a condition such as: "Service" is "Roof repair". */
			__( '"%s"', 'primiko' ),
			optionLabel( field, String( value ), custom )
		);

	if ( undefined !== condition.equals ) {
		return sprintf(
			/* translators: 1: question, 2: one or more answers. */
			__( '"%1$s" is %2$s', 'primiko' ),
			question,
			answer( condition.equals )
		);
	}

	if ( condition.in ) {
		return sprintf(
			/* translators: 1: question, 2: one or more answers. */
			__( '"%1$s" is %2$s', 'primiko' ),
			question,
			condition.in.map( answer ).join( or )
		);
	}

	if ( condition.not_in ) {
		return sprintf(
			/* translators: 1: question, 2: one or more answers. */
			__( '"%1$s" is not %2$s', 'primiko' ),
			question,
			condition.not_in.map( answer ).join( or )
		);
	}

	if ( condition.filled ) {
		return sprintf(
			/* translators: %s: question. */
			__( '"%s" is answered', 'primiko' ),
			question
		);
	}

	return sprintf(
		/* translators: %s: question. */
		__( '"%s" is left empty', 'primiko' ),
		question
	);
}

/**
 * Finds a question of the template.
 *
 * @param {Object} template Translated template.
 * @param {string} id       Question identifier.
 * @param {Object} custom   Customizations, for added questions.
 * @return {Object|undefined} Question.
 */
export function findField( template, id, custom ) {
	for ( const step of template.steps ) {
		const field = stepFields( step, custom ).find(
			( item ) => item.id === id
		);

		if ( field ) {
			return field;
		}
	}
}

/**
 * Effective text of a question.
 *
 * @param {Object} field  Question.
 * @param {Object} custom Customizations.
 * @return {string} Text.
 */
export function fieldLabel( field, custom ) {
	return getIn( custom, [ 'fields', field.id, 'label' ] ) || field.label;
}

/**
 * Effective text of an answer.
 *
 * @param {Object} field  Question.
 * @param {string} value  Answer value.
 * @param {Object} custom Customizations.
 * @return {string} Text.
 */
export function optionLabel( field, value, custom ) {
	if ( field && 'yesno' === field.type ) {
		return 'yes' === value ? __( 'Yes', 'primiko' ) : __( 'No', 'primiko' );
	}

	const option =
		field &&
		( field.options || [] ).find( ( item ) => item.value === value );

	return (
		getIn( custom, [
			'fields',
			field && field.id,
			'options',
			value,
			'label',
		] ) || ( option ? option.label : value )
	);
}
