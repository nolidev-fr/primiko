/**
 * Default @wordpress/scripts build (the blocks found in src/), plus the
 * questions editor of the form screen and the visitor script. The visitor
 * script source stays readable in assets/form.js, and ships with the plugin.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: () => ( {
		...defaultConfig.entry(),
		'admin/index': './src/admin/index.js',
		'form/form': './assets/form.js',
	} ),
};
