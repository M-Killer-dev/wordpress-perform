/**
 * External dependencies
 */
const wpConfig = require( '@wordpress/scripts/config/.eslintrc.js' );

const config = {
	...wpConfig,
	rules: {
		...( wpConfig?.rules || {} ),
		'jsdoc/valid-types': 'off',
	},
	env: {
		browser: true,
	},
	ignorePatterns: [ '/vendor', '/node_modules' ],
};

module.exports = config;
