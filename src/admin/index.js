/**
 * Mounts the questions editor on the form screen.
 */
import { createRoot, render } from '@wordpress/element';

import Editor from './editor';
import Picker from './picker';
import './style.scss';

/**
 * Renders a component, with createRoot where WordPress has it (6.2+).
 *
 * @param {Element} element React element.
 * @param {Element} node    Mount point.
 */
function show( element, node ) {
	if ( createRoot ) {
		createRoot( node ).render( element );
	} else {
		render( element, node );
	}
}

function mountPicker() {
	const node = document.getElementById( 'primiko-template-picker' );
	const select = document.getElementById( 'primiko-template' );

	if ( ! node || ! select ) {
		return;
	}

	// The select stays in the page as the saved field, the picker replaces it.
	select.hidden = true;
	show(
		<Picker
			config={ JSON.parse( node.getAttribute( 'data-config' ) ) }
			select={ select }
		/>,
		node
	);
}

function mount() {
	mountPicker();

	const node = document.getElementById( 'primiko-questions-editor' );
	const output = document.getElementById( 'primiko-customizations' );

	if ( ! node || ! output ) {
		return;
	}

	const editor = (
		<Editor
			config={ JSON.parse( node.getAttribute( 'data-config' ) ) }
			output={ output }
			select={ document.getElementById( 'primiko-template' ) }
		/>
	);

	show( editor, node );
}

if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
