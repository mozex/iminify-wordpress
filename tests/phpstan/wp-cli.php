<?php
/**
 * What the plugin uses of WP-CLI, for PHPStan, which never loads it.
 *
 * @package Iminify\WordPress
 */

namespace {

	class WP_CLI {

		/**
		 * @param string $name
		 * @param mixed  $callable
		 */
		public static function add_command( string $name, $callable ): void {}

		/**
		 * @return never
		 */
		public static function error( string $message ): void {
			exit( 1 );
		}

		public static function warning( string $message ): void {}

		public static function success( string $message ): void {}

		/**
		 * @param array<string, mixed> $assoc
		 */
		public static function confirm( string $question, array $assoc = array() ): void {}
	}
}

namespace cli\progress {

	class Bar {

		public function tick( int $increment = 1 ): void {}

		public function finish(): void {}
	}
}

namespace WP_CLI\Utils {

	function make_progress_bar( string $message, int $count ): \cli\progress\Bar {
		return new \cli\progress\Bar();
	}

	/**
	 * @param list<array<string, string>> $items
	 * @param list<string>                $fields
	 */
	function format_items( string $format, array $items, array $fields ): void {}
}
