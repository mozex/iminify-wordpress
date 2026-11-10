<?php
/**
 * What the page audit's screen shows.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Admin;

use Iminify\WordPress\Api\Client;
use Iminify\WordPress\Audit\Findings;
use Iminify\WordPress\Audit\Fixes;
use Iminify\WordPress\Audit\Resolver;
use Iminify\WordPress\Capabilities;
use Iminify\WordPress\Connection;
use Iminify\WordPress\Local_Settings;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Media\Shared;
use Iminify\WordPress\Messages;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Site_Record;
use Iminify\WordPress\Translations;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The screen's whole state, built here so its script only draws it: the pages
 * the site offers to audit, the day's page scans, the site's audits as
 * Iminify keeps them, and one audit with each image the page had beside the
 * attachment and size it is (`Resolver`), each finding in the site's language
 * with the fix Iminify suggests. Nothing of an audit is kept here: Iminify
 * holds them for the site.
 */
class Audit_State {

	/**
	 * How many of the site's published pages the screen offers beside its home page.
	 */
	const PAGES = 20;

	/**
	 * Builds the state, with the audit `$id` names open, else the newest.
	 *
	 * @param string|null $id The audit to open.
	 * @return array<string, mixed>
	 */
	public static function build( ?string $id = null ): array {
		$state = array(
			'connected' => Plugin::connected(),
			'home'      => home_url( '/' ),
			'pages'     => self::pages(),
			'scans'     => self::scans(),
			'audits'    => array(),
			'audit'     => null,
			'error'     => null,
			'revoked'   => false,
			'links'     => array(
				'settings' => Settings_Page::url(),
				'weekly'   => self::weekly(),
			),
		);

		$client = Plugin::client();

		if ( null === $client ) {
			$state['connected'] = false;
			$state['revoked']   = Connection::was_revoked();

			return $state;
		}

		$audits = $client->audits();

		if ( is_wp_error( $audits ) ) {
			return self::refused( $state, $audits );
		}

		$state['audits'] = array_values( array_filter( array_map( array( self::class, 'summary' ), $audits ) ) );
		$id              = $id ?? ( $state['audits'][0]['id'] ?? null );

		if ( null === $id ) {
			return $state;
		}

		$audit = self::audit( $client, $id );

		return is_wp_error( $audit ) ? self::refused( $state, $audit ) : array_merge( $state, array( 'audit' => $audit ) );
	}

	/**
	 * Whether a refusal is Iminify no longer knowing the site's key, which
	 * disconnects the site here as every other screen does (a key set in
	 * wp-config.php stays, and its refusal shows).
	 *
	 * @param WP_Error $error What the client answered.
	 */
	public static function revoked( WP_Error $error ): bool {
		if ( 'unauthenticated' !== $error->get_error_code() || Plugin::keys()->from_constant() ) {
			return false;
		}

		( new Connection() )->revoked_by_service( $error );

		return true;
	}

	/**
	 * The state with a refusal: a key Iminify stopped reads as disconnected,
	 * with nothing listed; anything else shows in the person's language.
	 *
	 * @param array<string, mixed> $state The state so far.
	 * @param WP_Error             $error What the client answered.
	 * @return array<string, mixed>
	 */
	protected static function refused( array $state, WP_Error $error ): array {
		if ( self::revoked( $error ) ) {
			return array_merge(
				$state,
				array(
					'connected' => Plugin::connected(),
					'revoked'   => Connection::was_revoked(),
					'audits'    => array(),
					'audit'     => null,
				)
			);
		}

		return array_merge( $state, array( 'error' => Messages::for_error( $error ) ) );
	}

	/**
	 * One audit as the screen draws it, read from Iminify.
	 *
	 * @param Client $client The site's client.
	 * @param string $id     The audit's id.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function audit( Client $client, string $id ) {
		$audit = $client->audit( $id );

		return is_wp_error( $audit ) ? $audit : self::detail( $audit );
	}

	/**
	 * An audit Iminify answered, with each image beside its attachment and size.
	 *
	 * @param array<string, mixed> $audit The audit.
	 * @return array<string, mixed>
	 */
	public static function detail( array $audit ): array {
		$images = isset( $audit['images'] ) && is_array( $audit['images'] ) ? array_values( array_filter( $audit['images'], 'is_array' ) ) : array();
		$urls   = array_values( array_unique( array_filter( array_map( array( self::class, 'url_of' ), $images ) ) ) );
		$found  = Resolver::resolve( $urls );
		$rows   = array();
		$post   = isset( $audit['url'] ) && is_string( $audit['url'] ) ? url_to_postid( $audit['url'] ) : 0;
		$held   = $post > 0 ? (string) get_post_field( 'post_content', $post ) : '';

		foreach ( $images as $image ) {
			$url      = self::url_of( $image );
			$wide     = self::number( $image['serve_width'] ?? null );
			$findings = self::findings( $image, $wide );
			$fixes    = Fixes::of( $url, array_column( $findings, 'code' ), $found[ $url ] ?? null, $wide );
			$rows[]   = array(
				'url'             => $url,
				'findings'        => $findings,
				'fixes'           => self::named( $fixes, $held, isset( $found[ $url ] ) ? $found[ $url ]['attachment'] : 0 ),
				'serve_width'     => $wide,
				'rendered_width'  => self::number( $image['rendered_width'] ?? null ),
				'rendered_height' => self::number( $image['rendered_height'] ?? null ),
				'natural_width'   => self::number( $image['natural_width'] ?? null ),
				'natural_height'  => self::number( $image['natural_height'] ?? null ),
				'largest_paint'   => true === ( $image['largest_paint'] ?? false ),
				'bytes'           => null === self::number( $image['bytes'] ?? null ) ? null : Translations::size( (int) $image['bytes'] ),
				'attachment'      => isset( $found[ $url ] ) ? self::attachment( $found[ $url ] ) : null,
			);
		}

		$rows    = self::optimize_once( $rows );
		$summary = (array) self::summary( $audit );

		return array_merge(
			$summary,
			array(
				'rendered' => true === ( $audit['rendered'] ?? false ),
				'images'   => $rows,
				'flagged'  => count(
					array_filter(
						$rows,
						static function ( array $row ): bool {
							return array() !== $row['findings'];
						}
					)
				),
				'edit'     => Fixes::edit_link( $post ),
				'complete' => self::complete( $rows ),
			)
		);
	}

	/**
	 * Optimize offered once an attachment, since one press sends every size of
	 * it: on its first image with something to fix, else its first image.
	 *
	 * @param list<array<string, mixed>> $rows The audit's images.
	 * @return list<array<string, mixed>>
	 */
	protected static function optimize_once( array $rows ): array {
		$offered = array();

		foreach ( array( true, false ) as $flagged ) {
			foreach ( $rows as $index => $row ) {
				if ( ( array() !== $row['findings'] ) !== $flagged || ! $row['fixes']['optimize'] ) {
					continue;
				}

				$attachment = $row['attachment']['id'];

				if ( isset( $offered[ $attachment ] ) ) {
					$rows[ $index ]['fixes']['optimize'] = false;
				}

				$offered[ $attachment ] = true;
			}
		}

		return $rows;
	}

	/**
	 * A smaller size's fix with the size's name as the media screens show it,
	 * and whether the page's own content holds the image, where the editor can
	 * change its size.
	 *
	 * @param array{optimize: bool, alt_text: string|null, fill: bool, smaller: array{size: string, width: int, height: int}|null} $fixes   The image's fixes.
	 * @param string                                                                                                             $content    The audited post's content, empty for a page that is none.
	 * @param int                                                                                                                $attachment The attachment the image is.
	 * @return array<string, mixed>
	 */
	protected static function named( array $fixes, string $content, int $attachment ): array {
		if ( null !== $fixes['smaller'] ) {
			$fixes['smaller']['size_name'] = self::size_name( $fixes['smaller']['size'] );
			$fixes['smaller']['editor']    = '' !== $content && Fixes::in_content( $content, array_merge( array( $attachment ), Shared::others( $attachment ) ) );
		}

		return $fixes;
	}

	/**
	 * Filling in what the page's markup lacks, as the screen offers it: how many
	 * images of the media library the page shows without their width and height
	 * or an alt text their attachment has, whether the site already fills them in,
	 * and whether the person may turn that on.
	 *
	 * @param list<array<string, mixed>> $rows The audit's images.
	 * @return array{images: int, on: bool, can: bool}
	 */
	protected static function complete( array $rows ): array {
		$images = 0;

		foreach ( $rows as $row ) {
			$codes = array_column( $row['findings'], 'code' );

			if ( null !== $row['attachment'] && $row['fixes']['fill'] && ( in_array( 'without_dimensions', $codes, true ) || 'fill' === $row['fixes']['alt_text'] ) ) {
				++$images;
			}
		}

		return array(
			'images' => $images,
			'on'     => Local_Settings::get()['complete_images'],
			'can'    => Capabilities::can( Capabilities::SETTINGS ),
		);
	}

	/**
	 * An image's findings in the plugin's words, each with the words for its fix.
	 *
	 * @param array<string, mixed> $image       An image of the audit.
	 * @param int|null             $serve_width For an oversized image, the width to show.
	 * @return list<array{code: string, fix: string, label: string, message: string, fix_text: string|null}>
	 */
	protected static function findings( array $image, ?int $serve_width ): array {
		$rows = array();

		foreach ( isset( $image['findings'] ) && is_array( $image['findings'] ) ? $image['findings'] : array() as $finding ) {
			$finding = Findings::finding( $finding );

			if ( null !== $finding ) {
				$rows[] = array_merge( $finding, array( 'fix_text' => Findings::fix( $finding['fix'], $serve_width ) ) );
			}
		}

		return $rows;
	}

	/**
	 * An audit as the list shows it, or null for one that isn't one.
	 *
	 * @param mixed $audit An audit Iminify answered.
	 * @return array{id: string, url: string, status: string, done: bool, problem: array<string, mixed>|null, images_found: int|null, at: string|null}|null
	 */
	public static function summary( $audit ): ?array {
		if ( ! is_array( $audit ) || ! isset( $audit['id'], $audit['url'] ) || ! is_string( $audit['id'] ) || ! is_string( $audit['url'] ) ) {
			return null;
		}

		return array(
			'id'           => $audit['id'],
			'url'          => $audit['url'],
			'status'       => (string) ( $audit['status'] ?? '' ),
			'done'         => true === ( $audit['done'] ?? false ),
			'problem'      => Findings::problem( $audit['problem'] ?? null ),
			'images_found' => self::number( $audit['images_found'] ?? null ),
			'at'           => State::date( $audit['created_at'] ?? null ),
		);
	}

	/**
	 * The attachment an image is, as the screen draws it beside the image.
	 *
	 * @param array{attachment: int, size: string, width: int, height: int, file: string, copy: string|null} $found What the resolver found.
	 * @return array<string, mixed>
	 */
	protected static function attachment( array $found ): array {
		$id     = $found['attachment'];
		$record = Records::get( $id );
		$edit   = get_edit_post_link( $id, 'raw' );
		$thumb  = wp_get_attachment_image_url( $id, 'thumbnail' );

		return array(
			'id'        => $id,
			'title'     => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
			'size'      => $found['size'],
			'size_name' => self::size_name( $found['size'] ),
			'width'     => $found['width'],
			'height'    => $found['height'],
			'copy'      => null === $found['copy'] ? null : strtoupper( $found['copy'] ),
			'status'    => (string) ( $record['status'] ?? Records::NOT_OPTIMIZED ),
			'thumbnail' => is_string( $thumb ) ? $thumb : null,
			'edit'      => is_string( $edit ) ? $edit : null,
		);
	}

	/**
	 * A size's name as WordPress's media screens show it.
	 *
	 * @param string $size The size's name.
	 */
	public static function size_name( string $size ): string {
		if ( 'original' === $size ) {
			return __( 'Original upload', 'iminify-image-optimizer' );
		}

		return Fixes::editor_sizes()[ $size ] ?? $size;
	}

	/**
	 * The home page and the site's latest published pages, to audit.
	 *
	 * @return list<array{title: string, url: string}>
	 */
	protected static function pages(): array {
		$pages = array(
			array(
				'title' => __( 'Home page', 'iminify-image-optimizer' ),
				'url'   => home_url( '/' ),
			),
		);

		$posts = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => self::PAGES,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'has_password'   => false,
			)
		);

		foreach ( $posts as $post ) {
			$url = (string) get_permalink( $post );

			if ( '' !== $url && trailingslashit( $url ) !== trailingslashit( home_url( '/' ) ) ) {
				$pages[] = array(
					'title' => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
					'url'   => $url,
				);
			}
		}

		return $pages;
	}

	/**
	 * Where a weekly check of the whole site is set up on www.iminify.com: on the
	 * website, from a crawl of the site, where every plan sees its controls and the
	 * service says which plans have it. Null while the site isn't connected or the
	 * account is kept out of sight (`Key_Store::hides_account()`).
	 */
	protected static function weekly(): ?string {
		if ( ! Plugin::connected() || Plugin::keys()->hides_account() ) {
			return null;
		}

		return Client::website( 'scan', array( 'utm_campaign' => 'audit' ) );
	}

	/**
	 * The day's page scans, which each audit spends, from the site's record
	 * (read again when none is kept), or null while the account is kept out
	 * of sight (`Key_Store::hides_account()`), as the settings page hides its counts.
	 *
	 * @return array{used: int, remaining: int|null, resets_at: string|null}|null
	 */
	protected static function scans(): ?array {
		if ( ! Plugin::connected() || Plugin::keys()->hides_account() ) {
			return null;
		}

		$site  = Site_Record::cached() ?? Site_Record::fetch();
		$scans = is_array( $site ) && isset( $site['usage']['scans'] ) && is_array( $site['usage']['scans'] ) ? $site['usage']['scans'] : null;

		if ( null === $scans ) {
			return null;
		}

		return array(
			'used'      => (int) ( $scans['used'] ?? 0 ),
			'remaining' => self::number( $scans['remaining'] ?? null ),
			'resets_at' => State::date( $scans['resets_at'] ?? null ),
		);
	}

	/**
	 * An image's address, or an empty string for one with none.
	 *
	 * @param array<string, mixed> $image An image of the audit.
	 */
	protected static function url_of( array $image ): string {
		return isset( $image['url'] ) && is_string( $image['url'] ) ? $image['url'] : '';
	}

	/**
	 * A whole number Iminify answered, or null.
	 *
	 * @param mixed $value The value.
	 */
	protected static function number( $value ): ?int {
		return is_int( $value ) ? $value : null;
	}
}
