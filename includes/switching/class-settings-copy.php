<?php
/**
 * Another optimizer's settings, kept on the site for the settings import.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Switching;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A copy of each other optimizer's settings (W138), as its reader names them
 * (`Reader::settings()`: only what Iminify's settings import maps, never a key
 * or a token), kept in an option of the plugin's own that isn't autoloaded,
 * since Imagify, Converter for Media, LiteSpeed Cache and Modern Image Formats
 * delete theirs when they are deleted. It is taken again whenever the plugin
 * looks (the settings page, and right before such a plugin is turned off or
 * deleted, while its options are still there), so it follows that plugin's
 * last settings, and it is kept once they are gone. Nothing in it leaves the
 * site until someone presses "Use <plugin>'s settings" (`Admin\Settings_Import`).
 *
 * Each plugin's entry also says whether the offer was answered, by using the
 * settings or turning them down, and holds the last proposal Iminify made,
 * which applying sends as it is.
 *
 * @phpstan-type Entry array{options: array<string, mixed>, taken: int, proposal?: array{settings: array<string, mixed>, backups: bool|null}, answered?: string}
 */
class Settings_Copy {

	const OPTION = 'iminify_other_settings';

	/**
	 * Takes the copy right before another optimizer is turned off or deleted.
	 */
	public static function register(): void {
		add_action( 'deactivate_plugin', array( self::class, 'before_leaving' ) );
		add_action( 'pre_uninstall_plugin', array( self::class, 'before_leaving' ) );
	}

	/**
	 * Another plugin is about to be turned off (still active, so its network
	 * settings read as the network's) or deleted (its uninstall about to run).
	 *
	 * @param mixed $plugin The plugin's main file.
	 */
	public static function before_leaving( $plugin ): void {
		foreach ( Others::readers() as $reader ) {
			if ( $reader->owns( (string) $plugin ) ) {
				self::take( $reader );

				return;
			}
		}
	}

	/**
	 * Takes a copy of every other optimizer's settings that are on the site.
	 */
	public static function take_all(): void {
		foreach ( Others::readers() as $reader ) {
			self::take( $reader );
		}
	}

	/**
	 * Takes a copy of one plugin's settings, while there are any: a plugin whose
	 * options are gone keeps its last copy.
	 *
	 * @param Reader $reader The plugin's reader.
	 */
	public static function take( Reader $reader ): void {
		$options = $reader->settings_chosen() ? $reader->settings() : array();

		if ( array() === $options ) {
			return;
		}

		$copies = self::all();
		$entry  = $copies[ $reader->slug() ] ?? null;

		if ( null !== $entry && $entry['options'] === $options ) {
			return;
		}

		$copies[ $reader->slug() ] = array_merge(
			$entry ?? array(),
			array(
				'options' => $options,
				'taken'   => time(),
			)
		);

		/* Proposed for the settings before: asked again on the next press. */
		unset( $copies[ $reader->slug() ]['proposal'] );

		update_option( self::OPTION, $copies, false );
	}

	/**
	 * The copy of one plugin's settings, or null.
	 *
	 * @param string $slug The plugin's short name.
	 * @return array<string, mixed>|null
	 */
	public static function options( string $slug ): ?array {
		$copies = self::all();

		return isset( $copies[ $slug ] ) ? $copies[ $slug ]['options'] : null;
	}

	/**
	 * The plugins whose settings the page offers to use: copied, and not answered yet.
	 *
	 * @return list<array{plugin: string, name: string}>
	 */
	public static function offers(): array {
		$offers = array();

		foreach ( self::all() as $slug => $entry ) {
			$reader = Others::reader( $slug );

			if ( null !== $reader && ! isset( $entry['answered'] ) ) {
				$offers[] = array(
					'plugin' => $slug,
					'name'   => $reader->name(),
				);
			}
		}

		return $offers;
	}

	/**
	 * Keeps what Iminify proposed for one plugin's settings, which applying sends.
	 *
	 * @param string                                                 $slug     The plugin's short name.
	 * @param array{settings: array<string, mixed>, backups: bool|null} $proposal What Iminify answered.
	 */
	public static function propose( string $slug, array $proposal ): void {
		$copies = self::all();

		if ( isset( $copies[ $slug ] ) ) {
			$copies[ $slug ]['proposal'] = $proposal;
			update_option( self::OPTION, $copies, false );
		}
	}

	/**
	 * What Iminify last proposed for one plugin's settings, or null.
	 *
	 * @param string $slug The plugin's short name.
	 * @return array{settings: array<string, mixed>, backups: bool|null}|null
	 */
	public static function proposal( string $slug ): ?array {
		$copies = self::all();

		return $copies[ $slug ]['proposal'] ?? null;
	}

	/**
	 * Records that the offer was answered (`used` or `declined`), so the page stops making it.
	 *
	 * @param string $slug   The plugin's short name.
	 * @param string $answer How.
	 */
	public static function answer( string $slug, string $answer ): void {
		$copies = self::all();

		if ( isset( $copies[ $slug ] ) ) {
			$copies[ $slug ]['answered'] = $answer;
			unset( $copies[ $slug ]['proposal'] );
			update_option( self::OPTION, $copies, false );
		}
	}

	/**
	 * Every copy, each entry of a shape the plugin wrote.
	 *
	 * @return array<string, Entry>
	 */
	protected static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$copies = array();

		foreach ( is_array( $stored ) ? $stored : array() as $slug => $entry ) {
			if ( is_string( $slug ) && is_array( $entry ) && isset( $entry['options'] ) && is_array( $entry['options'] ) ) {
				$entry['taken']  = (int) ( $entry['taken'] ?? 0 );
				$copies[ $slug ] = $entry;
			}
		}

		return $copies;
	}
}
