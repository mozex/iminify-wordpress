/**
 * The settings: the site's, kept and checked on iminify.com while the site
 * is connected, and the ones kept here, which work without a connection.
 * Core's form controls, set in the design system's panels.
 */
import {
	CheckboxControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { Button, Panel, PanelTitle } from '../ui';
import RulesPanel from './rules';

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

/**
 * The site's settings as the form holds them, with the number fields as text.
 *
 * @param {Object} settings The settings Iminify answered.
 * @return {Object} The form's values.
 */
function fromSite( settings ) {
	const text = ( value ) =>
		null === value || undefined === value ? '' : String( value );

	return {
		...settings,
		max_width: text( settings.max_width ),
		size_limit: text( settings.size_limit ),
	};
}

/**
 * What a way of serving the copies does, under its menu.
 *
 * @param {string} delivery The way chosen.
 * @return {string} The help text.
 */
function deliveryHelp( delivery ) {
	switch ( delivery ) {
		case 'page':
			return __(
				"Also reaches the images in raw HTML, page builders and your theme's own markup, the backgrounds set in the page itself, and the images in the folders you chose, by reading each page as it's sent. A background set in a stylesheet file keeps its original. A page your cache hasn't kept takes a little longer.",
				'iminify-image-optimizer'
			);
		case 'rules':
			return __(
				'Your pages stay as they are, and your server sends the WebP or AVIF copy in place of the image to each browser that reads it. That reaches every image in the uploads folder and the folders you chose, backgrounds in stylesheet files too. Needs rules in your server’s configuration, written for you on Apache, and behind a CDN, one that keeps a version of each image per format.',
				'iminify-image-optimizer'
			);
		case 'off':
			return __(
				'Your pages stay as they are: the copies are made, but no page names them.',
				'iminify-image-optimizer'
			);
		default:
			return __(
				'For the images in your posts and those your theme shows through WordPress. Each page is the same for every visitor, so page caches and CDNs keep working, and a browser that reads neither format gets the original.',
				'iminify-image-optimizer'
			);
	}
}

/**
 * What the form sends: the local settings, and the site's while it is connected.
 *
 * @param {boolean} connected Whether the site is connected.
 * @param {Object}  site      The site's settings in the form.
 * @param {Object}  local     The local settings in the form.
 * @return {Object} The changes.
 */
function changesOf( connected, site, local ) {
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
			alt_text: Boolean( site.alt_text ),
			alt_text_language: site.alt_text_language,
		};
	}

	return changes;
}

function FieldError( { errors, name } ) {
	return errors[ name ] ? (
		<span className="iminify-field-error">{ errors[ name ] }</span>
	) : null;
}

export default function SettingsForm( {
	state,
	busy,
	errors,
	onSave,
	onUnsaved,
} ) {
	const connected = 'connected' === state.connection;
	const [ site, setSite ] = useState( () =>
		fromSite( state.site?.settings ?? {} )
	);
	const [ local, setLocal ] = useState( state.local );
	const allSizes = state.sizes.map( ( size ) => size.name );
	const chosen = Array.isArray( site.sizes ) ? site.sizes : allSizes;
	/* What the server holds now: the form has changes once it differs. */
	const changed =
		JSON.stringify( changesOf( connected, site, local ) ) !==
		JSON.stringify(
			changesOf(
				connected,
				fromSite( state.site?.settings ?? {} ),
				state.local
			)
		);

	/* So "Use <plugin>'s settings" can say that using them drops what wasn't saved here. */
	useEffect( () => {
		onUnsaved?.( changed );
	}, [ changed, onUnsaved ] );

	const change = ( name ) => ( value ) =>
		setSite( ( current ) => ( { ...current, [ name ]: value } ) );

	const languages = state.site?.languages ?? [];

	/* The first time it is turned on, a site in another language starts on it, where the service writes it. */
	const changeAltText = ( on ) =>
		setSite( ( current ) => ( {
			...current,
			alt_text: on,
			alt_text_language:
				on && 'en' === current.alt_text_language && state.site?.language
					? state.site.language
					: current.alt_text_language,
		} ) );

	const save = ( event ) => {
		event.preventDefault();
		onSave( changesOf( connected, site, local ) );
	};

	return (
		<form onSubmit={ save } className="iminify-col">
			{ connected && (
				<Panel>
					<PanelTitle icon="sliders">
						{ __( 'Optimization', 'iminify-image-optimizer' ) }
					</PanelTitle>
					<p className="iminify-lead">
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
						help={ <FieldError errors={ errors } name="level" /> }
					/>
					<div className="iminify-pair">
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
					</div>
					<div className="iminify-pair">
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
									) }
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
									) }
									<FieldError
										errors={ errors }
										name="size_limit"
									/>
								</>
							}
						/>
					</div>
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
							'Write alt text with AI',
							'iminify-image-optimizer'
						) }
						help={ __(
							"Describes each image that has no alt text. An alt text you wrote is never replaced. A small copy of the image goes to Anthropic's AI, and each one counts against your AI alt texts for the day.",
							'iminify-image-optimizer'
						) }
						checked={ Boolean( site.alt_text ) }
						onChange={ changeAltText }
					/>
					{ Boolean( site.alt_text ) && languages.length > 0 && (
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __(
								'Language of the alt text',
								'iminify-image-optimizer'
							) }
							value={ site.alt_text_language }
							options={ languages }
							onChange={ change( 'alt_text_language' ) }
							help={
								<FieldError
									errors={ errors }
									name="alt_text_language"
								/>
							}
						/>
					) }
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
						<fieldset className="iminify-fieldset iminify-box">
							<legend className="iminify-sr">
								{ __(
									'Sizes to optimize',
									'iminify-image-optimizer'
								) }
							</legend>
							<span className="iminify-label" aria-hidden="true">
								{ __(
									'Sizes to optimize',
									'iminify-image-optimizer'
								) }
							</span>
							<div className="iminify-pair iminify-checks">
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
							</div>
							<FieldError errors={ errors } name="sizes" />
						</fieldset>
					) }
				</Panel>
			) }

			<Panel>
				<PanelTitle icon="server">
					{ __( 'On this site', 'iminify-image-optimizer' ) }
				</PanelTitle>
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
					help={ deliveryHelp( local.delivery ) }
					options={ [
						{
							value: 'picture',
							label: __(
								'Serve them with picture tags',
								'iminify-image-optimizer'
							),
						},
						{
							value: 'page',
							label: __(
								'Serve them on the whole page',
								'iminify-image-optimizer'
							),
						},
						{
							value: 'rules',
							label: __(
								'Serve them through server rules',
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
				{ /* What the server holds: the rules' state follows a saved choice. */ }
				{ 'rules' === local.delivery &&
					'rules' === state.local.delivery &&
					state.rules && <RulesPanel rules={ state.rules } /> }
				<fieldset className="iminify-fieldset iminify-box">
					<legend className="iminify-sr">
						{ __(
							'When you delete the plugin',
							'iminify-image-optimizer'
						) }
					</legend>
					<span className="iminify-label" aria-hidden="true">
						{ __(
							'When you delete the plugin',
							'iminify-image-optimizer'
						) }
					</span>
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
							"They're the only originals of the images Iminify optimized, so those images can't be restored afterwards. With the copies deleted too, installing the plugin again would optimize those images a second time.",
							'iminify-image-optimizer'
						) }
						checked={ Boolean( local.uninstall_backups ) }
						onChange={ ( on ) =>
							setLocal( { ...local, uninstall_backups: on } )
						}
					/>
				</fieldset>
			</Panel>

			<div className="iminify-savebar">
				<Button
					type="submit"
					variant="primary"
					busy={ busy }
					disabled={ busy }
				>
					{ __( 'Save changes', 'iminify-image-optimizer' ) }
				</Button>
				{ changed && (
					<span className="iminify-savebar__note" role="status">
						{ __( 'Unsaved changes', 'iminify-image-optimizer' ) }
					</span>
				) }
			</div>
		</form>
	);
}
