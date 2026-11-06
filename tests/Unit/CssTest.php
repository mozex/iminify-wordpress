<?php
/**
 * The background declarations of a piece of CSS, given their copies.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Iminify\WordPress\Delivery\Css;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Covers the copy added after a longhand and after a shorthand, with every
 * other part and `!important` as written, what is left alone (a value a browser
 * fills in later, an address it can't be sure of or inside another function, a
 * declaration already followed by its copy), and reading CSS that hides its
 * declarations in comments, strings, `data:` addresses and nested rules.
 */
class CssTest extends TestCase {

	const AVIF = 'image-set(url("/u/hero.jpg.avif") type("image/avif"), url("/u/hero.jpg.webp") type("image/webp"), ';

	protected function set_up(): void {
		parent::set_up();
		Monkey\setUp();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
	}

	protected function tear_down(): void {
		Monkey\tearDown();
		parent::tear_down();
	}

	public function test_a_longhand_and_a_shorthand_are_followed_by_a_copy_whose_addresses_list_their_copies(): void {
		$this->assertSame(
			'background-image: url(/u/hero.jpg); background-image: ' . self::AVIF . 'url(/u/hero.jpg) type("image/jpeg"));',
			Css::rewrite( 'background-image: url(/u/hero.jpg);', array( $this, 'copies' ) )
		);

		/* A builder's hero: a gradient over the photo, positioned, sized and kept with `!important`; the copy sets every part as the first does. */
		$css = "min-height:40vh;BACKGROUND : linear-gradient(rgba(0,0,0,.5), rgba(0,0,0,.5)), #fff url('/u/hero.jpg') center / cover no-repeat , url(/u/plain.png) repeat-x !important ;color:red";

		$this->assertSame(
			"min-height:40vh;BACKGROUND : linear-gradient(rgba(0,0,0,.5), rgba(0,0,0,.5)), #fff url('/u/hero.jpg') center / cover no-repeat , url(/u/plain.png) repeat-x !important; BACKGROUND: linear-gradient(rgba(0,0,0,.5), rgba(0,0,0,.5)), #fff " . self::AVIF . "url('/u/hero.jpg') type(\"image/jpeg\")) center / cover no-repeat , url(/u/plain.png) repeat-x !important ;color:red",
			Css::rewrite( $css, array( $this, 'copies' ) )
		);

		/* The original's type follows its extension, and one with none it knows gets no type; every address with copies is listed, in every layer. */
		$this->assertSame(
			'background:url("/u/a.PNG") no-repeat, url(/u/hero.jpg) #000; background:image-set(url("/u/a.PNG.webp") type("image/webp"), url("/u/a.PNG") type("image/png")) no-repeat, ' . self::AVIF . 'url(/u/hero.jpg) type("image/jpeg")) #000',
			Css::rewrite( 'background:url("/u/a.PNG") no-repeat, url(/u/hero.jpg) #000', array( $this, 'copies' ) )
		);
		$this->assertSame(
			'background: url(/u/image?id=3) no-repeat rgb(0 0 0); background: image-set(url("/u/image?id=3.webp") type("image/webp"), url(/u/image?id=3)) no-repeat rgb(0 0 0)',
			Css::rewrite( 'background: url(/u/image?id=3) no-repeat rgb(0 0 0)', array( $this, 'copies' ) )
		);
	}

	public function test_a_declaration_it_cant_be_sure_of_or_one_already_followed_by_its_copy_is_left_as_it_is(): void {
		$cases = array(
			'no copies'                 => 'background-image: url(/u/none.jpg)',
			'a custom property'         => 'background: var(--overlay), url(/u/hero.jpg)',
			'one inside a gradient'     => 'background: linear-gradient(VAR(--tint), red), url(/u/hero.jpg)',
			'an environment value'      => 'background: url(/u/hero.jpg) env(safe-area-inset-top)',
			'an attribute'              => 'background-image: attr(data-bg type(<url>)), url(/u/hero.jpg)',
			'a condition'               => 'background-image: url(/u/hero.jpg), if(media(width > 40em): none; else: none)',
			'a custom function'         => 'background: url(/u/hero.jpg), --tint(red)',
			'an image-set already'      => 'background-image: image-set(url(/u/hero.jpg) 1x, url(/u/hero@2x.jpg) 2x)',
			'a prefixed image-set'      => 'background-image: -webkit-image-set(url(/u/hero.jpg) 1x)',
			'an escaped address'        => 'background-image: url(/u/he\\(ro.jpg)',
			'an escaped quoted address' => 'background-image: url("/u/he\\"ro.jpg")',
			'a space in the address'    => 'background-image: url(/u/he ro.jpg)',
			'a url() of more'           => 'background: url("/u/hero.jpg" x)',
			'another property'          => '--hero: url(/u/hero.jpg); mask-image: url(/u/hero.jpg); content: url(/u/hero.jpg)',
			'a name running into it'    => 'background-image: myurl(/u/hero.jpg)',
			'left open'                 => 'background-image: linear-gradient(red, url(/u/hero.jpg)',
			'a string left open'        => "background:url(\"/u/hero.jpgx\n)",
			'a url() left open'         => 'background:url(/u/hero.jpg',
		);

		foreach ( $cases as $case => $css ) {
			$this->assertSame( $css, Css::rewrite( $css, array( $this, 'copies' ) ), $case );
		}

		/* An address it can't read, or one inside another function, stays as written beside one it swaps. */
		$this->assertSame(
			'background: url(/u/he\\(ro.jpg), image-set(url(/u/x.jpg) 1x), url(/u/hero.jpg); background: url(/u/he\\(ro.jpg), image-set(url(/u/x.jpg) 1x), ' . self::AVIF . 'url(/u/hero.jpg) type("image/jpeg"))',
			Css::rewrite( 'background: url(/u/he\\(ro.jpg), image-set(url(/u/x.jpg) 1x), url(/u/hero.jpg)', array( $this, 'copies' ) )
		);

		/* A copy only stands for its own declaration: one ended by a brace, one with a declaration between, or one of another property still gains its copy. */
		foreach ( array( '.a{background:url(/u/hero.jpg);}.b{background:image-set(url(/u/x.jpg) 1x)}', 'background:url(/u/hero.jpg)}background:image-set(url(/u/x.jpg) 1x)', 'background:url(/u/hero.jpg);background-image:image-set(url(/u/x.jpg) 1x)' ) as $css ) {
			$this->assertStringContainsString( 'background:url(/u/hero.jpg); background:' . self::AVIF, Css::rewrite( $css, array( $this, 'copies' ) ), $css );
		}

		/* An escaped parenthesis inside a function ends no function. */
		$this->assertSame(
			'background: paint(\\() , url(/u/hero.jpg); background: paint(\\() , ' . self::AVIF . 'url(/u/hero.jpg) type("image/jpeg"))',
			Css::rewrite( 'background: paint(\\() , url(/u/hero.jpg)', array( $this, 'copies' ) )
		);

		/* What it adds, read again (a page an editor saved back with it), gains nothing more. */
		$once = Css::rewrite( '.a{background:url(/u/hero.jpg) center}.b{background-image:url(/u/hero.jpg)}', array( $this, 'copies' ) );

		$this->assertSame( 2, substr_count( $once, 'image-set(' ) );
		$this->assertSame( $once, Css::rewrite( $once, array( $this, 'copies' ) ) );
	}

	public function test_declarations_are_found_only_where_css_has_them(): void {
		$css = "/* background: url(/u/comment.jpg) */\n"
			. ".x{color:red} /* it's {background:url(/u/comment.jpg)} */ .y{background-image:url(/u/y.jpg)}\n"
			. ".a::after{content:\"x; background: url(/u/string.jpg)\"}\n"
			. "background-image:hover{background-image:url(/u/nested.jpg)}\n"
			. "@media (min-width: 40em) { .hero { background-image: url( '/u/hero.jpg' ) } }\n"
			. "@supports (display: grid) { .b { background: url(/u/plain.png) } }\n"
			. ".c { --x: url(/u/var.jpg); background-image: url(/u/c.jpg); }\n"
			. "@import url(/u/import.css);\n"
			. '.d{background-image:url(/u/d.jpg)/* trailing */;background:none}';

		$this->assertSame( array( '/u/y.jpg', '/u/nested.jpg', '/u/hero.jpg', '/u/plain.png', '/u/c.jpg', '/u/d.jpg' ), Css::urls( $css ) );

		/* An escaped quote in a selector (Tailwind's `content-['']`), a string a line break ends, and a capital `URL(` holding a quote all end where a browser ends them. */
		$after = '.hero{background-image:url(/u/hero.jpg)}';

		foreach ( array( ".before\\:content-\\[\\'\\'\\]::before{--tw-content:'';}", ".a{font-family:Bob's}\n", ".a{background:URL(/u/it's.jpg)}" ) as $before ) {
			$this->assertSame( array( '/u/hero.jpg' ), Css::urls( $before . $after ), $before );
		}

		$rewritten = Css::rewrite( $css, array( $this, 'copies' ) );

		$this->assertStringContainsString( "background-image: url( '/u/hero.jpg' ); background-image: " . self::AVIF . "url( '/u/hero.jpg' ) type(\"image/jpeg\")) } }", $rewritten );
		$this->assertStringContainsString( 'background-image:url(/u/d.jpg)/* trailing */; background-image:image-set(url("/u/d.jpg.webp") type("image/webp"), url(/u/d.jpg) type("image/jpeg"))/* trailing */;background:none', $rewritten );
		$this->assertStringNotContainsString( 'comment.jpg.', $rewritten );
		$this->assertStringNotContainsString( 'string.jpg.', $rewritten );
		$this->assertStringNotContainsString( 'var.jpg.', $rewritten );
		$this->assertStringContainsString( 'background-image:hover{background-image:url(/u/nested.jpg); background-image:image-set(url("/u/nested.jpg.webp") type("image/webp"), url(/u/nested.jpg) type("image/jpeg"))}', $rewritten, 'A selector that reads like a property is no declaration.' );
		$this->assertSame( 5, substr_count( $rewritten, 'image-set(' ), 'The shorthand in `@supports` has no copies.' );
	}

	public function test_a_data_address_of_megabytes_holding_semicolons_is_read_in_one_pass(): void {
		$data = 'url(data:image/svg+xml;utf8,' . str_repeat( '<path d="M0 0;"/>', 150000 ) . ')';
		$css  = 'background-image: ' . $data . '; background: url(/u/hero.jpg) center';

		$started   = microtime( true );
		$rewritten = Css::rewrite( $css, array( $this, 'copies' ) );

		$this->assertLessThan( 5, microtime( true ) - $started );
		$this->assertSame( $css . '; background: ' . self::AVIF . 'url(/u/hero.jpg) type("image/jpeg")) center', $rewritten );
		$this->assertSame( array( '/u/hero.jpg' ), Css::urls( $css ), 'An address with a quote in it is no address.' );
	}

	public function test_a_copys_address_is_written_as_a_css_string(): void {
		$this->assertSame(
			"background-image:url('/u/it\"s.jpg'); background-image:image-set(url(\"/u/it\\\"s.jpg.webp\") type(\"image/webp\"), url('/u/it\"s.jpg') type(\"image/jpeg\"))",
			Css::rewrite( "background-image:url('/u/it\"s.jpg')", array( $this, 'copies' ) )
		);
	}

	/**
	 * The copies the tests' files have: `hero.jpg` both, `plain.png` and `none.jpg`
	 * none, every other file a WebP copy.
	 *
	 * @param string $url The file's address.
	 * @return array<string, string>
	 */
	public function copies( string $url ): array {
		if ( '/u/hero.jpg' === $url ) {
			return array(
				'image/avif' => $url . '.avif',
				'image/webp' => $url . '.webp',
			);
		}

		return in_array( $url, array( '/u/plain.png', '/u/none.jpg' ), true ) ? array() : array( 'image/webp' => $url . '.webp' );
	}
}
