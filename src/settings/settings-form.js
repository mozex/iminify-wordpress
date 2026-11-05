/**
 * The settings: the site's, kept and checked on iminify.com while the site
 * is connected, and the ones kept here, which work without a connection.
 */
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * A number field's value as the service takes it: a whole number, or null when empty.
 *
 * @param {string} value What the field holds.
 * @return {number|null|string} The number, null, or the text as typed for the service to refuse.
 */
function whole( value ) {
	const text = String( value ?? '' ).trim();

	if ( '' === text ) {
		return null;
	}

	return /^\d+$/.test( text ) ? Number( text ) : text;
}

function FieldError( { errors, name } ) {
	return errors[ name ] ? (
		<span className="iminify-field-error">{ errors[ name ] }</span>
	) : null;
}

export default function SettingsForm( { state, busy, errors, onSave } ) {
	const connected = 'connected' === state.connection;
	const initial = state.site?.settings ?? {};
	const [ site, setSite ] = useState( {
		...initial,
		max_width:
			null === initial.max_width || undefined === initial.max_width
				? ''
				: String( initial.max_width ),
		size_limit:
			null === initial.size_limit || undefined === initial.size_limit
				? ''
				: String( initial.size_limit ),
	} );
	const [ local, setLocal ] = useState( state.local );
	const allSizes = state.sizes.map( ( size ) => size.name );
	const chosen = Array.isArray( site.sizes ) ? site.sizes : allSizes;

	const change = ( name ) => ( value ) =>
		setSite( ( current ) => ( { ...current, [ name ]: value } ) );

	const save = ( event ) => {
		event.preventDefault();

		const changes = { local };

		if ( connected ) {
			changes.site = {
				optimize_on_upload: site.optimize_on_upload,
				level: site.level,
				webp: site.webp,
				avif: site.avif,
				max_width: whole( site.max_width ),
				size_limit: whole( site.size_limit ),
				keep_metadata: site.keep_metadata,
				sizes: site.sizes ?? null,
			};
		}

		onSave( changes );
	};

	return (
		<form onSubmit={ save }>
			{ connected && (
				<Card className="iminify-card">
					<CardHeader>
						<h2>
							{ __( 'Optimization', 'iminify-image-optimizer' ) }
						</h2>
					</CardHeader>
					<CardBody>
						<p className="description">
							{ __(
								'These settings are kept with the site on iminify.com, which applies them to every image the site sends.',
								'iminify-image-optimizer'
							) }
						</p>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Optimize new uploads',
								'iminify-image-optimizer'
							) }
							help={ __(
								'Send each image to Iminify as it is uploaded.',
								'iminify-image-optimizer'
							) }
							checked={ Boolean( site.optimize_on_upload ) }
							onChange={ change( 'optimize_on_upload' ) }
						/>
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __(
								'Compression level',
								'iminify-image-optimizer'
							) }
							value={ site.level }
							options={ state.levels }
							onChange={ change( 'level' ) }
							help={
								<FieldError errors={ errors } name="level" />
							}
						/>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Make WebP copies',
								'iminify-image-optimizer'
							) }
							help={ __(
								"A WebP copy of every size, served to browsers that read it. A copy larger than its image isn't kept.",
								'iminify-image-optimizer'
							) }
							checked={ Boolean( site.webp ) }
							onChange={ change( 'webp' ) }
						/>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Make AVIF copies',
								'iminify-image-optimizer'
							) }
							help={ __(
								'An AVIF copy of every size too: usually smaller than WebP, and slower to make.',
								'iminify-image-optimizer'
							) }
							checked={ Boolean( site.avif ) }
							onChange={ change( 'avif' ) }
						/>
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							type="number"
							min="1"
							label={ __(
								'Maximum width, in pixels',
								'iminify-image-optimizer'
							) }
							value={ site.max_width }
							onChange={ change( 'max_width' ) }
							help={
								<>
									{ __(
										"Shrinks a wider main image to this width. Leave it empty to keep each image's width.",
										'iminify-image-optimizer'
									) }{ ' ' }
									<FieldError
										errors={ errors }
										name="max_width"
									/>
								</>
							}
						/>
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							type="number"
							min="1"
							label={ __(
								'Size limit, in KB',
								'iminify-image-optimizer'
							) }
							value={ site.size_limit }
							onChange={ change( 'size_limit' ) }
							help={
								<>
									{ __(
										'Fits the main image under this size. Leave it empty for no limit.',
										'iminify-image-optimizer'
									) }{ ' ' }
									<FieldError
										errors={ errors }
										name="size_limit"
									/>
								</>
							}
						/>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Keep metadata',
								'iminify-image-optimizer'
							) }
							help={ __(
								"Keeps each image's EXIF data, such as the camera and the place it was taken. Off removes it.",
								'iminify-image-optimizer'
							) }
							checked={ Boolean( site.keep_metadata ) }
							onChange={ change( 'keep_metadata' ) }
						/>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Optimize every size',
								'iminify-image-optimizer'
							) }
							help={ __(
								'Every size WordPress makes of an upload. Turn it off to pick the sizes.',
								'iminify-image-optimizer'
							) }
							checked={ ! Array.isArray( site.sizes ) }
							onChange={ ( every ) =>
								change( 'sizes' )( every ? null : allSizes )
							}
						/>
						{ Array.isArray( site.sizes ) && (
							<fieldset className="iminify-sizes">
								<legend>
									{ __(
										'Sizes to optimize',
										'iminify-image-optimizer'
									) }
								</legend>
								{ state.sizes.map( ( size ) => (
									<CheckboxControl
										__nextHasNoMarginBottom
										key={ size.name }
										label={ size.label }
										checked={ chosen.includes( size.name ) }
										onChange={ ( on ) =>
											change( 'sizes' )(
												on
													? [ ...chosen, size.name ]
													: chosen.filter(
															( name ) =>
																name !==
																size.name
														)
											)
										}
									/>
								) ) }
								<FieldError errors={ errors } name="sizes" />
							</fieldset>
						) }
					</CardBody>
				</Card>
			) }

			<Card className="iminify-card">
				<CardHeader>
					<h2>{ __( 'On this site', 'iminify-image-optimizer' ) }</h2>
				</CardHeader>
				<CardBody>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Keep a backup of each original',
							'iminify-image-optimizer'
						) }
						help={ __(
							'Kept in the uploads folder under iminify-backup, so any image can be restored as it was.',
							'iminify-image-optimizer'
						) }
						checked={ Boolean( local.backups ) }
						onChange={ ( backups ) =>
							setLocal( { ...local, backups } )
						}
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __(
							'WebP and AVIF on your pages',
							'iminify-image-optimizer'
						) }
						value={ local.delivery }
						options={ [
							{
								value: 'picture',
								label: __(
									'Serve them with picture tags',
									'iminify-image-optimizer'
								),
							},
							{
								value: 'off',
								label: __(
									"Don't change the pages",
									'iminify-image-optimizer'
								),
							},
						].filter( ( option ) =>
							state.deliveries.includes( option.value )
						) }
						onChange={ ( delivery ) =>
							setLocal( { ...local, delivery } )
						}
					/>
					<fieldset className="iminify-sizes iminify-uninstall">
						<legend>
							{ __(
								'When you delete the plugin',
								'iminify-image-optimizer'
							) }
						</legend>
						<CheckboxControl
							__nextHasNoMarginBottom
							label={ __(
								'Also delete the WebP and AVIF copies',
								'iminify-image-optimizer'
							) }
							help={ __(
								'A page your cache kept with picture tags would show a broken image until the cache is cleared.',
								'iminify-image-optimizer'
							) }
							checked={ Boolean( local.uninstall_copies ) }
							onChange={ ( on ) =>
								setLocal( { ...local, uninstall_copies: on } )
							}
						/>
						<CheckboxControl
							__nextHasNoMarginBottom
							label={ __(
								'Also delete the backups',
								'iminify-image-optimizer'
							) }
							help={ __(
								"They're the only originals of the images Iminify optimized, so those images can't be restored afterwards.",
								'iminify-image-optimizer'
							) }
							checked={ Boolean( local.uninstall_backups ) }
							onChange={ ( on ) =>
								setLocal( { ...local, uninstall_backups: on } )
							}
						/>
					</fieldset>
				</CardBody>
			</Card>

			<p>
				<Button
					type="submit"
					variant="primary"
					__next40pxDefaultSize
					isBusy={ busy }
					disabled={ busy }
				>
					{ __( 'Save changes', 'iminify-image-optimizer' ) }
				</Button>
			</p>
		</form>
	);
}
