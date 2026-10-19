/**
 * Quote request form block. Rendered on the server, like the shortcode.
 */
import { registerBlockType } from '@wordpress/blocks';

import metadata from './block.json';
import Edit from './edit';

// The Primiko spirit level.
const icon = (
	<svg
		viewBox="0 0 24 24"
		xmlns="http://www.w3.org/2000/svg"
		aria-hidden="true"
	>
		<path
			fillRule="evenodd"
			d="M2 7.5A1.5 1.5 0 0 1 3.5 6h17A1.5 1.5 0 0 1 22 7.5v9a1.5 1.5 0 0 1-1.5 1.5h-17A1.5 1.5 0 0 1 2 16.5ZM8.5 9a3 3 0 0 0 0 6h7a3 3 0 0 0 0-6Z"
		/>
		<ellipse cx="12" cy="12" rx="1.7" ry="1.2" />
	</svg>
);

registerBlockType( metadata.name, {
	icon,
	edit: Edit,
	save: () => null,
} );
