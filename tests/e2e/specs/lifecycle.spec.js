/**
 * Deleting the plugin as WordPress does it, with the plugin's own file never
 * loaded, and the privacy policy guide's text.
 */
const { test, expect } = require( '@playwright/test' );
const { wp, seed, seedJson } = require( '../support/wp' );

const plugin = 'iminify-image-optimizer';

test( 'Deleting the plugin stops the key and keeps the copies, the backups and the records', () => {
	seed( 'reset' );
	seed( 'connect' );
	const { id } = seedJson( 'optimized' );

	/* uninstall_plugin() runs uninstall.php without deleting a file: wp-env maps the plugin folder onto the checkout. */
	wp( 'plugin', 'deactivate', plugin );
	wp( 'eval', `uninstall_plugin( '${ plugin }/${ plugin }.php' );` );

	const left = JSON.parse(
		wp(
			'eval',
			`global $wpdb;
			$record = get_post_meta( ${ id }, '_iminify', true );
			$copies = array_keys( $record['copies'] ?? array() );
			echo wp_json_encode( array(
				'options' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'iminify\\_%' AND option_name NOT LIKE 'iminify\\_e2e\\_%'" ),
				'status'  => get_post_meta( ${ id }, '_iminify_status', true ),
				'copy'    => array() !== $copies && is_file( wp_get_upload_dir()['basedir'] . '/' . $copies[0] ),
				'backup'  => is_file( wp_get_upload_dir()['basedir'] . '/' . ( $record['backups'][0] ?? 'none' ) ),
				'deleted' => count( array_filter( (array) get_option( 'iminify_e2e_calls', array() ), static function ( $call ) { return 'DELETE' === $call['method'] && 'site' === $call['path']; } ) ),
			) );`
		)
	);

	wp( 'plugin', 'activate', plugin );

	expect( left ).toEqual( { options: 0, status: 'optimized', copy: true, backup: true, deleted: 1 } );
} );

test( 'The privacy policy guide offers the plugin\'s text', async ( { page } ) => {
	await page.goto( '/wp-admin/options-privacy.php?tab=policyguide' );
	await page.getByRole( 'button', { name: /Iminify Image Optimizer/ } ).first().click();
	await expect( page.getByText( 'Nothing about the people who visit this site is sent to Iminify' ) ).toBeVisible();
} );
