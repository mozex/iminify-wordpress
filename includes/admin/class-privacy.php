<?php
/**
 * The plugin's suggested text for the site's privacy policy.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Offered on Settings > Privacy's policy guide, as core asks of every plugin that
 * sends data to another service. It says what reaches Iminify and that nothing
 * about the site's visitors does, and it matches what the readme's "External
 * services" section says: change both together.
 */
class Privacy {

	/**
	 * Adds the text to the policy guide (on `admin_init`).
	 */
	public static function register(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( __( 'Iminify Image Optimizer', 'iminify-image-optimizer' ), self::text() );
		}
	}

	/**
	 * The suggested text, as the policy guide shows it.
	 */
	public static function text(): string {
		$paragraphs = array(
			'<p class="privacy-policy-tutorial">' . esc_html__( 'Iminify Image Optimizer sends the images uploaded to this site to Iminify, an image optimization service, once the site is connected to it. You can copy the text below into the part of your policy about the media you publish.', 'iminify-image-optimizer' ) . '</p>',
			'<p><strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'iminify-image-optimizer' ) . ' </strong>'
				. esc_html__( 'This site uses Iminify (www.iminify.com) to make its images smaller and to make WebP and AVIF copies of them. When an image is uploaded here, or we optimize the images already here, in the media library or in our theme and other folders, our server sends it to Iminify with this site\'s address, and Iminify sends the smaller files back. Iminify deletes the image once we have saved them.', 'iminify-image-optimizer' ) . '</p>',
			'<p>' . esc_html__( 'A photo can carry details the camera wrote into it, such as when and where it was taken. Those details reach Iminify with the image, and Iminify leaves them out of the files it sends back unless we chose to keep them.', 'iminify-image-optimizer' ) . '</p>',
			'<p>' . esc_html__( 'Where this site is part of a multisite network connected to Iminify as one, our server also sends Iminify this site\'s address and name the first time the site needs it.', 'iminify-image-optimizer' ) . '</p>',
			'<p>' . esc_html__( 'Nothing about the people who visit this site is sent to Iminify: the images on our pages, and their WebP and AVIF copies, are served from this site.', 'iminify-image-optimizer' ) . '</p>',
			'<p>' . sprintf(
				/* translators: %s: the address of Iminify's privacy policy. */
				esc_html__( 'Iminify\'s privacy policy: %s', 'iminify-image-optimizer' ),
				'<a href="https://www.iminify.com/privacy-policy">https://www.iminify.com/privacy-policy</a>'
			) . '</p>',
		);

		return implode( "\n", $paragraphs );
	}
}
