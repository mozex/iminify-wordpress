<?php
/**
 * A stand-in for WP-CLI in the integration tests, which run without it.
 *
 * @package Iminify\WordPress
 */

namespace {

	/**
	 * Records what a command prints, and throws where WP-CLI would exit.
	 */
	class WP_CLI {

		/**
		 * What the command printed, each as a kind and its text.
		 *
		 * @var list<array{0: string, 1: string}>
		 */
		public static $lines = array();

		/**
		 * The commands added.
		 *
		 * @var array<string, mixed>
		 */
		public static $commands = array();

		/**
		 * Adds a command.
		 *
		 * @param string $name  The command's name.
		 * @param mixed  $class What runs it.
		 */
		public static function add_command( string $name, $class ): void {
			self::$commands[ $name ] = $class;
		}

		/**
		 * Stops with an error.
		 *
		 * @param string $message The error.
		 * @throws RuntimeException Always, where WP-CLI would exit.
		 */
		public static function error( string $message ): void {
			self::$lines[] = array( 'error', $message );

			throw new RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a test's stand-in.
		}

		/**
		 * Prints a warning.
		 *
		 * @param string $message The warning.
		 */
		public static function warning( string $message ): void {
			self::$lines[] = array( 'warning', $message );
		}

		/**
		 * Prints a success.
		 *
		 * @param string $message The success.
		 */
		public static function success( string $message ): void {
			self::$lines[] = array( 'success', $message );
		}

		/**
		 * Asks, unless `--yes` was passed.
		 *
		 * @param string               $question The question.
		 * @param array<string, mixed> $assoc    The flags.
		 * @throws RuntimeException Where WP-CLI would wait for an answer.
		 */
		public static function confirm( string $question, array $assoc = array() ): void {
			if ( empty( $assoc['yes'] ) ) {
				self::$lines[] = array( 'asked', $question );

				throw new RuntimeException( 'Asked.' );
			}
		}
	}
}

namespace WP_CLI\Utils {

	/**
	 * A progress bar that remembers how far it got.
	 *
	 * @param string $message Its label.
	 * @param int    $count   Its length.
	 */
	function make_progress_bar( string $message, int $count ): object {
		unset( $message, $count );

		return new class() {

			/**
			 * How far it got.
			 *
			 * @var int
			 */
			public $ticks = 0;

			/**
			 * Moves on.
			 *
			 * @param int $by How far.
			 */
			public function tick( int $by = 1 ): void {
				$this->ticks += $by;
			}

			/**
			 * Ends.
			 */
			public function finish(): void {
				\WP_CLI::$lines[] = array( 'bar', (string) $this->ticks );
			}
		};
	}

	/**
	 * Keeps the rows it was asked to print.
	 *
	 * @param string                      $format How to print them.
	 * @param list<array<string, string>> $items  The rows.
	 * @param list<string>                $fields The columns.
	 */
	function format_items( string $format, array $items, array $fields ): void {
		unset( $format, $fields );

		foreach ( $items as $item ) {
			\WP_CLI::$lines[] = array( 'row', implode( '=', $item ) );
		}
	}
}
