/**
 * The few lines that keep WooCommerce's variation images working inside a
 * picture tag, run in a page that does to the product image what WooCommerce's
 * own script does: it stores each attribute in `data-o_*` before its first
 * change, sets the variation's, puts the stored ones back on Reset, and
 * replaces the gallery's markup for a variation with a gallery of its own.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { test, expect } = require( '@playwright/test' );

const php = fs.readFileSync(
	path.join( __dirname, '../../../includes/compat/class-woocommerce.php' ),
	'utf8'
);
const script = php.slice(
	php.indexOf( "<<<'JS'" ) + "<<<'JS'".length,
	php.indexOf( '\nJS;' )
);

const webp =
	'data:image/webp;base64,UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAwA0JaQAA3AA/vuUAAA=';
const png =
	'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
const gif =
	'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

const gallery = ( src ) =>
	`<div class="woocommerce-product-gallery"><div class="woocommerce-product-gallery__image"><a href="#"><picture style="display: contents;"><source type="image/webp" srcset="${ webp } 1w"><img data-iminify-picture class="wp-post-image" src="${ src }" srcset="${ src } 1w" sizes="1px" alt=""></picture></a></div></div>`;

test( 'A product image shows each variation chosen and its own copy again on Reset, in a gallery replaced since too', async ( {
	page,
} ) => {
	await page.setContent(
		`<!doctype html><html><body>${ gallery( png ) }</body></html>`
	);
	await page.addScriptTag( { content: script } );

	const state = () =>
		page.evaluate( () => {
			const image = document.querySelector(
				'.woocommerce-product-gallery img'
			);

			return new Promise( ( resolve ) =>
				window.requestAnimationFrame( () =>
					setTimeout(
						() =>
							resolve( {
								shown: image.currentSrc.slice( 0, 15 ),
								sources: [
									...image.parentNode.querySelectorAll(
										'source'
									),
								].map( ( source ) =>
									source.hasAttribute( 'srcset' )
								),
							} ),
						50
					)
				)
			);
		} );

	/* WooCommerce's set and reset, as `wc_set_variation_attr()` and `wc_reset_variation_attr()` write them. */
	const set = ( variation ) =>
		page.evaluate( ( value ) => {
			const image = document.querySelector(
				'.woocommerce-product-gallery img'
			);

			for ( const attribute of [ 'src', 'srcset' ] ) {
				if ( ! image.hasAttribute( 'data-o_' + attribute ) ) {
					image.setAttribute(
						'data-o_' + attribute,
						image.getAttribute( attribute ) || ''
					);
				}
			}

			image.setAttribute( 'src', value );
			image.setAttribute( 'srcset', value + ' 1w' );
		}, variation );
	const reset = () =>
		page.evaluate( () => {
			const image = document.querySelector(
				'.woocommerce-product-gallery img'
			);

			for ( const attribute of [ 'src', 'srcset' ] ) {
				if ( image.hasAttribute( 'data-o_' + attribute ) ) {
					image.setAttribute(
						attribute,
						image.getAttribute( 'data-o_' + attribute )
					);
				}
			}
		} );

	expect( await state() ).toEqual( {
		shown: webp.slice( 0, 15 ),
		sources: [ true ],
	} );

	/* A lazy loader swapping its placeholder stores nothing, so the copy stays. */
	await page.evaluate(
		( loaded ) =>
			document
				.querySelector( '.woocommerce-product-gallery img' )
				.setAttribute( 'src', loaded + '#loaded' ),
		png
	);
	expect( ( await state() ).sources ).toEqual( [ true ] );

	await set( gif );
	expect( await state() ).toEqual( {
		shown: gif.slice( 0, 15 ),
		sources: [ false ],
	} );

	await reset();
	expect( await state() ).toEqual( {
		shown: webp.slice( 0, 15 ),
		sources: [ true ],
	} );

	/* A variation with a gallery of its own replaces the gallery's markup; its image is watched as well. */
	await page.evaluate( ( html ) => {
		const template = document.createElement( 'template' );
		template.innerHTML = html;
		document
			.querySelector( '.woocommerce-product-gallery' )
			.replaceWith( template.content.firstChild );
	}, gallery( png ) );
	expect( await state() ).toEqual( {
		shown: webp.slice( 0, 15 ),
		sources: [ true ],
	} );

	await set( gif );
	expect( await state() ).toEqual( {
		shown: gif.slice( 0, 15 ),
		sources: [ false ],
	} );
} );
