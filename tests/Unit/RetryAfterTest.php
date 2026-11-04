<?php
/**
 * Reading the wait a Retry-After header asks for.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Unit;

use Iminify\WordPress\Api\Client;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Covers both forms the header takes and what isn't a wait.
 */
class RetryAfterTest extends TestCase {

	/**
	 * Header values and the seconds they mean.
	 *
	 * @return array<string, array{0: string|string[], 1: int|null}>
	 */
	public function headers(): array {
		return array(
			'seconds'           => array( '30', 30 ),
			'seconds, spaced'   => array( ' 120 ', 120 ),
			'a list'            => array( array( '45', '90' ), 45 ),
			'a date passed'     => array( 'Wed, 21 Oct 2015 07:28:00 GMT', 0 ),
			'missing'           => array( '', null ),
			'not a wait'        => array( 'soon', null ),
			'a negative number' => array( '-5', null ),
		);
	}

	/**
	 * @dataProvider headers
	 *
	 * @param string|string[] $header   The header's value.
	 * @param int|null        $expected The seconds to wait.
	 */
	public function test_the_header_reads_as_seconds( $header, ?int $expected ): void {
		$this->assertSame( $expected, Client::retry_after( $header ) );
	}

	public function test_a_date_ahead_reads_as_the_seconds_until_it(): void {
		$wait = Client::retry_after( gmdate( 'D, d M Y H:i:s', time() + 600 ) . ' GMT' );

		$this->assertGreaterThanOrEqual( 598, $wait );
		$this->assertLessThanOrEqual( 600, $wait );
	}
}
