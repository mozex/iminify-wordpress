/**
 * Where the server's rules stand, under the setting that turns them on: the
 * file they went into or what to paste where they couldn't, Nginx's lines, and
 * what the last look at the site's own answers found. Drawn from what the
 * server built (`Delivery\Rules::state()`).
 */
import { createInterpolateElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { Callout, CodeCopy } from '../ui';

/**
 * A file's path, set apart in the sentence.
 *
 * @param {string} text The sentence, with `<file />` where the path goes.
 * @param {string} file The path.
 * @return {Element} The sentence.
 */
function withFile( text, file ) {
	return createInterpolateElement( text, {
		file: <code dir="ltr">{ file }</code>,
	} );
}

/**
 * What the last look at the site's answers found.
 *
 * @param {Object} props       The component's props.
 * @param {Object} props.rules The rules' state.
 */
function Serving( { rules } ) {
	if ( rules.refused ) {
		return (
			<Callout tone="danger">
				{ __(
					'Your server answered with an error once the rules were in, so the plugin took them out again and your images load as before. Ask your host whether .htaccess files may set rewrite rules and file types, or choose picture tags.',
					'iminify-image-optimizer'
				) }
			</Callout>
		);
	}

	if ( true === rules.serves ) {
		return (
			<Callout tone="success" icon="check">
				{ sprintf(
					/* translators: %s: the date and time of the last check. */
					__(
						'Your server sends the WebP and AVIF copies. Checked %s.',
						'iminify-image-optimizer'
					),
					rules.checked
				) }
			</Callout>
		);
	}

	if ( false === rules.serves ) {
		/* translators: %s: the date and time of the last check. */
		let text = __(
			'Your server still sends the originals: something answers for images before these rules are read, often Nginx in front of Apache. Add the Nginx lines below to its configuration, or choose picture tags. Checked %s.',
			'iminify-image-optimizer'
		);

		if ( 'nginx' === rules.server ) {
			/* translators: %s: the date and time of the last check. */
			text = __(
				'Your server still sends the originals. Add the lines below to its configuration and reload Nginx, then save again. Checked %s.',
				'iminify-image-optimizer'
			);
		} else if ( rules.cloudflare ) {
			/* translators: %s: the date and time of the last check. */
			text = __(
				'Cloudflare answered with the original, so a visitor may never get a copy through these rules. Choose picture tags, which work behind Cloudflare. Checked %s.',
				'iminify-image-optimizer'
			);
		}

		return (
			<Callout tone="warning">{ sprintf( text, rules.checked ) }</Callout>
		);
	}

	return (
		<Callout tone="info" icon="server">
			{ sprintf(
				/* translators: %s: the date and time of the last check. */
				__(
					"The plugin couldn't ask your site for an image to see whether the rules work, which happens when a site can't reach its own address. Open one of your images with a browser's developer tools: its type should read image/avif or image/webp. Tried %s.",
					'iminify-image-optimizer'
				),
				rules.checked
			) }
		</Callout>
	);
}

/**
 * Where the Apache rules went, or what to paste where they couldn't.
 *
 * @param {Object} props       The component's props.
 * @param {Object} props.rules The rules' state.
 */
function Apache( { rules } ) {
	/* The refusal says what happened; nothing is there to paste or to find. */
	if ( rules.refused ) {
		return null;
	}

	switch ( rules.where ) {
		case 'written':
			return (
				<p className="iminify-text">
					{ withFile(
						__(
							'The rules are in <file />.',
							'iminify-image-optimizer'
						),
						rules.file
					) }
				</p>
			);
		case 'damaged':
			return (
				<Callout tone="warning">
					{ withFile(
						sprintf(
							/* translators: %1$s: the name of the plugin's block in the file, such as "Iminify". */
							__(
								'<file /> holds the line # BEGIN %1$s without its # END %1$s line, so the plugin leaves the file alone. Put the line # END %1$s back after the block’s last </IfModule>, or delete every line of the block, then save again.',
								'iminify-image-optimizer'
							),
							rules.marker
						),
						rules.file
					) }
				</Callout>
			);
		case 'outside':
			return (
				<Callout tone="warning">
					{ __(
						"Your uploads folder isn't inside the folder that holds the site's .htaccess file, so no rule there can name it. Choose picture tags.",
						'iminify-image-optimizer'
					) }
				</Callout>
			);
		default:
			return (
				<div className="iminify-rules__part">
					<p className="iminify-text">
						{ withFile(
							{
								missing: __(
									'There’s no <file /> yet. Create it with these lines, then save again.',
									'iminify-image-optimizer'
								),
								no_rewrite: __(
									'<file /> doesn’t turn on rewriting, so the plugin leaves it alone. If your server reads rewrite rules there, paste these lines into it, then save again.',
									'iminify-image-optimizer'
								),
							}[ rules.where ] ??
								( rules.network
									? __(
											'The plugin can’t write to <file />. Paste these lines into it, above the rules WordPress gave the network, then save again.',
											'iminify-image-optimizer'
										)
									: __(
											'The plugin can’t write to <file />. Paste these lines at its end, then save again.',
											'iminify-image-optimizer'
										) ),
							rules.file
						) }
					</p>
					{ rules.lines && (
						<CodeCopy command={ rules.lines } block />
					) }
				</div>
			);
	}
}

export default function RulesPanel( { rules } ) {
	/* Whether the look's verdict is about the rules: once they are in, on Nginx where nothing is written, or when the server sends the copies anyway. */
	const looked =
		'written' === rules.where ||
		'nginx' === rules.server ||
		true === rules.serves ||
		rules.refused;
	/* Behind Cloudflare an original sent back says nothing about Nginx. */
	const nginx =
		'nginx' === rules.server ||
		( 'written' === rules.where &&
			false === rules.serves &&
			! rules.cloudflare );
	const covered =
		'other' === rules.server || rules.refused
			? []
			: rules.places
					.filter( ( place ) => place.covered )
					.map( ( place ) => place.name );

	return (
		<div className="iminify-rules">
			{ 'other' === rules.server ? (
				<Callout tone="warning">
					{ __(
						"Your server reads neither .htaccess files nor Nginx's configuration, so the plugin has no rules to give it. Choose picture tags.",
						'iminify-image-optimizer'
					) }
				</Callout>
			) : (
				<>
					{ 'apache' === rules.server && <Apache rules={ rules } /> }
					{ looked && <Serving rules={ rules } /> }
					{ nginx && rules.nginx && (
						<div className="iminify-rules__part">
							<p className="iminify-text">
								{ 'nginx' === rules.server
									? __(
											"Your site runs on Nginx, which doesn't read .htaccess. Put the first lines at the top of the site's server block, before any if or rewrite, and the location above any other location for images, then reload Nginx. Copy into the location any expires, Cache-Control or add_header lines your images need, since its add_header replaces the server block's:",
											'iminify-image-optimizer'
										)
									: __(
											"For Nginx, put the first lines at the top of the site's server block, before any if or rewrite, and the location above any other location for images, then reload Nginx. Copy into the location any expires, Cache-Control or add_header lines your images need, since its add_header replaces the server block's:",
											'iminify-image-optimizer'
										) }
							</p>
							<CodeCopy command={ rules.nginx } block />
						</div>
					) }
				</>
			) }
			{ covered.length > 0 && (
				<p className="iminify-text">
					{ sprintf(
						/* translators: %s: the folders the rules cover, separated by commas. */
						__( 'They cover: %s.', 'iminify-image-optimizer' ),
						covered.join( ', ' )
					) }
				</p>
			) }
			{ covered.length > 0 && (
				<p className="iminify-text">
					{ __(
						'They serve any WebP or AVIF copy named after an image beside it, so a copy another optimizer left there is served too until Iminify makes its own. Iminify never deletes another plugin’s copies.',
						'iminify-image-optimizer'
					) }
				</p>
			) }
			{ rules.cloudflare && (
				<Callout tone="warning">
					{ __(
						"Cloudflare answers for your site. It keeps one version of each image whatever the browser reads, so a browser that can't show AVIF could get the AVIF copy. Choose picture tags, which work behind any CDN.",
						'iminify-image-optimizer'
					) }
				</Callout>
			) }
			{ rules.offload && (
				<Callout tone="info" icon="server">
					{ __(
						'WP Offload Media serves your images from its bucket, which these rules never reach, so those keep their originals. With its “Remove Local Media” on, the rules serve nothing.',
						'iminify-image-optimizer'
					) }
				</Callout>
			) }
		</div>
	);
}
