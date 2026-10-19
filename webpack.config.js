/**
 * Default @wordpress/scripts build (the blocks found in src/), plus the
 * questions editor of the form screen.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: () => ( {
		...defaultConfig.entry(),
		'admin/index': './src/admin/index.js',
	} ),
};
