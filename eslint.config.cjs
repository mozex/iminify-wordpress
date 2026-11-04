/**
 * wp-scripts' own rules, with WordPress's packages read as what they are at
 * runtime: core's copies, loaded as script dependencies, never installed here.
 */
const config = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...config,
	{
		rules: {
			'import/no-unresolved': [ 'error', { ignore: [ '^@wordpress/' ] } ],
			'import/no-extraneous-dependencies': 'off',
		},
	},
];
