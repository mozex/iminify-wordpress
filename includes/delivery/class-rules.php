<?php
/**
 * The server's rules that send a WebP or AVIF copy in place of the image itself.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Delivery;

use Iminify\WordPress\Compat\Caches;
use Iminify\WordPress\Compat\Offload_Media;
use Iminify\WordPress\Folders\Folders;
use Iminify\WordPress\Folders\Restorer as Folder_Restorer;
use Iminify\WordPress\Folders\Table;
use Iminify\WordPress\Local_Settings;
use Iminify\WordPress\Loopback;
use Iminify\WordPress\Media\Copies;
use Iminify\WordPress\Media\Files;
use Iminify\WordPress\Media\Optimizer;
use Iminify\WordPress\Media\Paths;
use Iminify\WordPress\Media\Records;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * With the server's rules on (`Local_Settings::DELIVERY_RULES`), the pages stay
 * as they are and the server answers a request for an image with its copy beside
 * it (`photo.jpg.avif`, then `photo.jpg.webp`) when the browser's `Accept` header
 * names that format, with `Vary: Accept`. Every request for an image is reached
 * that way, a background in a stylesheet file included.
 *
 * On Apache and LiteSpeed the rules are a block of the site's own `.htaccess`,
 * the one WordPress writes its permalinks to (`file()`), where `place()` puts
 * it: a block in the uploads folder would turn off every root rule there
 * (hotlink protection, a redirect). They match only the uploads folder
 * and the folders chosen under Other Folders, by their path from that `.htaccess`.
 * The block is written only where the file already turns rewriting on, which
 * proves the server reads rewrite rules there and allows them; anywhere else,
 * and on Nginx, the settings show what to paste. Each site of a network keeps a
 * block of its own (`marker()`) for its own uploads folder; the main site's
 * leaves the other sites' folders (`sites/N/`) out, and only it names the chosen
 * folders, which are its own.
 *
 * The rules can only ask whether a copy is there, never the record, so in this
 * mode the plugin's own copy is taken off the disk as soon as the plugin sees
 * its file change (`Hooks::changed()`, `Folders\Scanner`), and when the mode is
 * turned on or the plugin comes back on in it (`sweep()`). A copy another tool
 * wrote beside an image is served too.
 */
class Rules {

	/**
	 * The marker of the main site's block, and of a single site's.
	 */
	const MARKER = 'Iminify';

	/**
	 * Where the last look at whether the server applies the rules is kept.
	 */
	const CHECK_OPTION = 'iminify_rules_check';

	/**
	 * How long a look at the rules stands before the settings page looks again.
	 */
	const CHECK_EVERY = 12 * HOUR_IN_SECONDS;

	/**
	 * The background pass that takes stale copies off the disk once the rules are on.
	 */
	const SWEEP_HOOK = 'iminify_sweep_stale_copies';

	/**
	 * How many images or files a step of the pass looks at.
	 */
	const SWEEP_PAGE = 100;

	/**
	 * The file the look at the rules asks the server for, in the uploads folder.
	 */
	const PROBE = 'iminify-rules-check.png';

	/**
	 * The bytes of the look's file and of its AVIF copy, told apart in the answer.
	 */
	const PROBE_BYTES = array(
		'png'  => 'iminify-rules-check: original',
		'avif' => 'iminify-rules-check: avif',
	);

	/**
	 * The extensions of the images the rules serve copies of, as a pattern; a WebP
	 * source has only an AVIF copy.
	 */
	const IMAGES = 'jpe?g|jpe|png|gif|webp';

	const IMAGES_FOR_WEBP = 'jpe?g|jpe|png|gif';

	/**
	 * The characters a place's path may hold to be written into a rule as it is.
	 */
	const SAFE_PATH = '#^[A-Za-z0-9._~@+/-]+$#';

	/**
	 * Hooks the rules to the setting and the pass to Action Scheduler.
	 */
	public static function register(): void {
		add_action( 'add_option_' . Local_Settings::OPTION, array( self::class, 'setting_added' ), 10, 2 );
		add_action( 'update_option_' . Local_Settings::OPTION, array( self::class, 'setting_changed' ), 10, 2 );
		add_action( self::SWEEP_HOOK, array( self::class, 'sweep' ), 10, 2 );
	}

	/**
	 * Whether the site serves its copies through the rules.
	 */
	public static function active(): bool {
		return Local_Settings::DELIVERY_RULES === Local_Settings::get()['delivery'];
	}

	/**
	 * The settings were saved for the first time: a change from the defaults.
	 *
	 * @param mixed $option The option's name.
	 * @param mixed $value  The settings.
	 */
	public static function setting_added( $option, $value ): void {
		self::setting_changed( Local_Settings::defaults(), $value );
	}

	/**
	 * The setting changed: a new way of serving puts the rules in or takes them
	 * out, the pages a cache kept are emptied, since they print the old way's
	 * markup (a `<picture>` naming copies the rules may now take off the disk), and
	 * turning the rules on starts the pass over the copies already stale. Filling
	 * in the images' markup (`Complete`) turned on or off empties them too.
	 *
	 * @param mixed $old   The settings before.
	 * @param mixed $value The settings now.
	 */
	public static function setting_changed( $old, $value ): void {
		$old    = Local_Settings::sanitize( $old );
		$value  = Local_Settings::sanitize( $value );
		$before = $old['delivery'];
		$now    = $value['delivery'];

		if ( $before === $now ) {
			if ( $old['complete_images'] !== $value['complete_images'] ) {
				Caches::later( 'delivery' );
			}

			return;
		}

		self::sync();
		Caches::later( 'delivery' );

		if ( Local_Settings::DELIVERY_RULES === $now ) {
			self::start_sweep();
		}
	}

	/**
	 * Puts the rules in while the site serves through them, where the server reads
	 * an `.htaccess`, and takes them out otherwise. Nothing changes on Nginx or IIS.
	 * Once the server refused them (`check()`), they go back in only when someone
	 * saves the settings again, or every page load would break the site anew.
	 *
	 * @param bool $retry Whether someone asked for them again after a refusal.
	 * @return bool Whether the file says what it should.
	 */
	public static function sync( bool $retry = false ): bool {
		if ( ! self::active() ) {
			return self::remove();
		}

		if ( ! $retry && self::refused() ) {
			return false;
		}

		return 'apache' === self::server() && self::write();
	}

	/**
	 * Whether the last look found the server refusing the rules.
	 */
	public static function refused(): bool {
		$kept = get_option( self::CHECK_OPTION );

		return is_array( $kept ) && ! empty( $kept['refused'] );
	}

	/**
	 * Where the block goes in the file's lines: on a single site at the end, so
	 * every rule already there (hotlink protection, a redirect, another plugin's,
	 * WordPress's own) runs first, and a block another plugin appends later is
	 * moved above it at the next write; on a network right before WordPress's
	 * rules, else at the top, since they end the rewriting of a file that exists
	 * (their `-f` rule), and the network's rules often have no markers at all.
	 *
	 * @param list<string> $lines The file's lines, without this site's block.
	 */
	protected static function place( array $lines ): int {
		if ( ! is_multisite() ) {
			$at = count( $lines );

			/* After the last line that says anything, so the file keeps its own ending. */
			while ( $at > 0 && '' === trim( $lines[ $at - 1 ] ) ) {
				--$at;
			}

			return $at;
		}

		foreach ( $lines as $index => $line ) {
			if ( 0 === strpos( trim( $line ), '# BEGIN WordPress' ) ) {
				return $index;
			}
		}

		return 0;
	}

	/**
	 * Writes this site's block where it belongs (`place()`), or moves it there.
	 *
	 * @return bool Whether the block is in the file as it should be.
	 */
	public static function write(): bool {
		$lines = self::lines();

		if ( null === $lines ) {
			return false;
		}

		$block = self::block( $lines );

		return self::edit(
			static function ( array $file ) use ( $block ): ?array {
				$file = self::without_block( $file );

				if ( null === $file || ! self::rewrites( $file ) ) {
					return null;
				}

				array_splice( $file, self::place( $file ), 0, array_merge( $block, array( '' ) ) );

				return $file;
			}
		);
	}

	/**
	 * Takes this site's block out of the file, with the blank line after it.
	 *
	 * @return bool Whether the file holds no block of this site's now.
	 */
	public static function remove(): bool {
		$file = self::file();

		if ( null === $file || ! is_file( $file ) || ! in_array( '# BEGIN ' . self::marker(), array_map( 'trim', self::read( $file ) ), true ) ) {
			return true;
		}

		return self::edit(
			static function ( array $lines ): ?array {
				return self::without_block( $lines );
			}
		);
	}

	/**
	 * The block as it stands in the file, with WordPress's instructions and the markers.
	 *
	 * @param list<string> $lines The rules.
	 * @return list<string>
	 */
	public static function block( array $lines ): array {
		$marker = self::marker();

		return array_merge(
			array(
				'# BEGIN ' . $marker,
				'# The directives (lines) between "BEGIN ' . $marker . '" and "END ' . $marker . '" are written by',
				'# the Iminify Image Optimizer plugin while it serves WebP and AVIF through these',
				'# rules. Any changes to them will be overwritten.',
			),
			$lines,
			array( '# END ' . $marker )
		);
	}

	/**
	 * This site's rules for Apache, or null when no place they cover is under the
	 * folder the `.htaccess` is in.
	 *
	 * @return list<string>|null
	 */
	public static function lines(): ?array {
		$places = self::patterns();

		if ( array() === $places ) {
			return null;
		}

		$under = '^(?:' . implode( '|', $places ) . ')';

		return array(
			'<IfModule mod_mime.c>',
			'AddType image/avif .avif',
			'AddType image/webp .webp',
			'</IfModule>',
			'<IfModule mod_rewrite.c>',
			'RewriteEngine On',
			'RewriteCond %{HTTP_ACCEPT} ^',
			'RewriteRule ' . $under . '.+\.(?:' . self::IMAGES . ')$ - [NC]',
			'RewriteCond %{HTTP_ACCEPT} image/avif [NC]',
			'RewriteCond %{REQUEST_FILENAME} -f',
			'RewriteCond %{REQUEST_FILENAME}.avif -f',
			'RewriteRule ' . $under . '.+\.(?:' . self::IMAGES . ')$ %{REQUEST_URI}.avif [NC,T=image/avif,L]',
			'RewriteCond %{HTTP_ACCEPT} image/webp [NC]',
			'RewriteCond %{REQUEST_FILENAME} -f',
			'RewriteCond %{REQUEST_FILENAME}.webp -f',
			'RewriteRule ' . $under . '.+\.(?:' . self::IMAGES_FOR_WEBP . ')$ %{REQUEST_URI}.webp [NC,T=image/webp,L]',
			'</IfModule>',
		);
	}

	/**
	 * The same for Nginx, for the site's server block: which copy the browser
	 * accepts is read at the server's level, since an `if` inside a location hands
	 * the request to a location of its own where `try_files` never runs, and a
	 * location by the places' addresses as Nginx matches them (a network's site
	 * under its own path is rewritten to the network's before a location is
	 * chosen) tries the AVIF, then the WebP, then the image. A format the browser
	 * doesn't accept names a file that never exists (`.iminify-none`).
	 */
	public static function nginx(): ?string {
		$places = self::patterns();

		if ( array() === $places ) {
			return null;
		}

		$network = is_multisite() ? get_network() : null;
		$base    = null !== $network ? $network->path : (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$base    = '/' . ltrim( trailingslashit( '' === $base ? '/' : $base ), '/' );
		$base    = str_replace( array( '.', '+' ), array( '\.', '\+' ), $base );

		return implode(
			"\n",
			array(
				'set $iminify_avif ".iminify-none";',
				'if ($http_accept ~* "image/avif") {',
				"\tset \$iminify_avif \".avif\";",
				'}',
				'set $iminify_webp ".iminify-none";',
				'if ($http_accept ~* "image/webp") {',
				"\tset \$iminify_webp \".webp\";",
				'}',
				'location ~* ^' . $base . '(?:' . implode( '|', $places ) . ').+\.(?:' . self::IMAGES . ')$ {',
				"\tadd_header Vary Accept;",
				"\ttypes {",
				"\t\timage/avif avif;",
				"\t\timage/webp webp;",
				"\t\timage/jpeg jpg jpeg jpe;",
				"\t\timage/png png;",
				"\t\timage/gif gif;",
				"\t}",
				"\ttry_files \$uri\$iminify_avif \$uri\$iminify_webp \$uri =404;",
				'}',
			)
		);
	}

	/**
	 * The places the rules cover, each as a pattern of its path from the folder the
	 * `.htaccess` is in, ending in a slash: the site's uploads folder (the main
	 * site's without the other sites' folders), then on the site that lists them
	 * the chosen folders. A place outside that folder, or whose path holds a
	 * character a rule can't take as it is, is left out.
	 *
	 * @return list<string>
	 */
	public static function patterns(): array {
		$patterns = array();
		$uploads  = self::from_home( Paths::uploads() );

		if ( null !== $uploads ) {
			$patterns[] = self::escape( $uploads ) . '/' . ( is_multisite() && is_main_site() ? '(?!sites/[0-9]+/)' : '' );
		}

		foreach ( Folders::listed() as $folder ) {
			$path = self::from_home( Folders::absolute( $folder ) );

			if ( null !== $path ) {
				$patterns[] = self::escape( $path ) . '/';
			}
		}

		return $patterns;
	}

	/**
	 * The places the rules cover, as the settings page names them.
	 *
	 * @return list<array{name: string, covered: bool}>
	 */
	public static function places(): array {
		$places = array(
			array(
				'name'    => __( 'The uploads folder', 'iminify-image-optimizer' ),
				'covered' => null !== self::from_home( Paths::uploads() ),
			),
		);

		foreach ( Folders::listed() as $folder ) {
			$places[] = array(
				'name'    => 'wp-content/' . $folder,
				'covered' => null !== self::from_home( Folders::absolute( $folder ) ),
			);
		}

		return $places;
	}

	/**
	 * Which server the site runs on, for what the settings offer: `apache` (also
	 * LiteSpeed, and any server WordPress can't name, as under WP-CLI), `nginx` or
	 * `other` (IIS).
	 */
	public static function server(): string {
		global $is_nginx, $is_iis7, $is_IIS;

		if ( ! empty( $is_nginx ) ) {
			return 'nginx';
		}

		return ! empty( $is_iis7 ) || ! empty( $is_IIS ) ? 'other' : 'apache';
	}

	/**
	 * The site's `.htaccess`, beside WordPress's own rules, or null when it can't
	 * be told. It is in the folder of the site's home address: WordPress's own
	 * folder, or for WordPress in a folder of its own (a site address ending in
	 * `/wp` past the home address) the folder above it. Core's `get_home_path()`
	 * tells that from the script that runs, which in a REST, WP-CLI or front-end
	 * request isn't WordPress's own, so it is told from `ABSPATH` here.
	 */
	public static function file(): ?string {
		$abspath = trailingslashit( wp_normalize_path( ABSPATH ) );
		$home    = set_url_scheme( (string) get_option( 'home' ), 'http' );
		$site    = set_url_scheme( (string) get_option( 'siteurl' ), 'http' );

		if ( '' === $home || 0 === strcasecmp( untrailingslashit( $home ), untrailingslashit( $site ) ) ) {
			return $abspath . '.htaccess';
		}

		$beyond = trim( (string) str_ireplace( untrailingslashit( $home ), '', $site ), '/' );
		$length = strlen( $beyond ) + 1;

		/* The folder WordPress is in, whole: `/wp/`, never the end of `/mywp/`. */
		if ( '' === $beyond || 0 !== strcasecmp( substr( $abspath, -$length - 1 ), '/' . $beyond . '/' ) ) {
			return null;
		}

		return substr( $abspath, 0, -$length ) . '.htaccess';
	}

	/**
	 * Where the rules stand in the file: `written`; `missing` (no `.htaccess`),
	 * `unwritable` (or a write that failed), `no_rewrite` (the file doesn't turn
	 * rewriting on, so the plugin doesn't write there), `damaged` (the block's
	 * BEGIN line has no END line, so the plugin leaves the file alone), `outside`
	 * (no place under the folder the file is in), or `server` (Nginx or IIS, which
	 * never read it).
	 */
	public static function where(): string {
		if ( 'apache' !== self::server() ) {
			return 'server';
		}

		$lines = self::lines();

		if ( null === $lines ) {
			return 'outside';
		}

		$file = self::file();

		if ( null === $file || ! is_file( $file ) ) {
			return 'missing';
		}

		$read    = self::read( $file );
		$without = self::without_block( $read );

		/* Before anything else, since neither a write nor taking the block out touches such a file. */
		if ( null === $without ) {
			return 'damaged';
		}

		if ( self::has_block( $read, self::block( $lines ) ) ) {
			return 'written';
		}

		/* Lines pasted where the plugin may not write turn rewriting on themselves, so they prove nothing about the file. */
		if ( ! self::rewrites( $without ) ) {
			return 'no_rewrite';
		}

		return 'unwritable';
	}

	/**
	 * Asks the site for a file of its own the way a browser would, once with AVIF
	 * accepted and once without, and keeps what the answers say: whether the server
	 * sent the copy, and whether Cloudflare answered (it keeps one answer per
	 * address, whatever a browser accepts). Each request carries an address of its
	 * own, so a cache in front answers neither from the other. A server that fails
	 * the plain request with an error once the rules are in refuses them: they
	 * come out.
	 *
	 * @return array{at: int, where: string, serves: bool|null, cloudflare: bool, refused: bool}
	 */
	public static function check(): array {
		$folder = Paths::uploads();
		$probe  = $folder . '/' . self::PROBE;
		$result = array(
			'at'         => time(),
			'where'      => self::where(),
			'serves'     => null,
			'cloudflare' => false,
			'refused'    => false,
		);

		if ( Files::put( $probe, self::PROBE_BYTES['png'] ) && Files::put( $probe . '.avif', self::PROBE_BYTES['avif'] ) ) {
			$url   = untrailingslashit( (string) wp_get_upload_dir()['baseurl'] ) . '/' . self::PROBE;
			$plain = self::ask( add_query_arg( 'iminify-check', time() . '-plain', $url ), '*/*' );
			$avif  = self::ask( add_query_arg( 'iminify-check', time() . '-avif', $url ), 'image/avif,image/webp,*/*' );

			foreach ( array( $plain, $avif ) as $answer ) {
				if ( ! is_wp_error( $answer ) && '' !== wp_remote_retrieve_header( $answer, 'cf-ray' ) ) {
					$result['cloudflare'] = true;
				}
			}

			$code = is_wp_error( $plain ) ? 0 : (int) wp_remote_retrieve_response_code( $plain );

			/* A server error with no rules in is the server's own, which the rules can't be blamed for. */
			if ( $code >= 500 && 'written' === $result['where'] ) {
				$result['refused'] = true;
				$result['serves']  = false;
				self::remove();
				$result['where'] = self::where();
			} elseif ( 200 === $code && ! is_wp_error( $avif ) && 200 === (int) wp_remote_retrieve_response_code( $avif ) ) {
				$body = wp_remote_retrieve_body( $avif );

				if ( self::PROBE_BYTES['avif'] === $body ) {
					$result['serves'] = true;
				} elseif ( self::PROBE_BYTES['png'] === $body ) {
					$result['serves'] = false;
				}
			}
		}

		wp_delete_file( $probe . '.avif' );
		wp_delete_file( $probe );
		update_option( self::CHECK_OPTION, $result, false );

		return $result;
	}

	/**
	 * The last look at the rules, taken again once it is older than `CHECK_EVERY`
	 * or the rules stand elsewhere than they did then (written since, or taken out);
	 * a refusal stands until someone saves the settings again.
	 *
	 * @return array{at: int, where: string, serves: bool|null, cloudflare: bool, refused: bool}
	 */
	public static function last_check(): array {
		$kept = get_option( self::CHECK_OPTION );

		if ( ! is_array( $kept ) || ! isset( $kept['at'], $kept['where'] ) || ( empty( $kept['refused'] ) && ( (int) $kept['at'] < time() - self::CHECK_EVERY || self::where() !== $kept['where'] ) ) ) {
			return self::check();
		}

		return array(
			'at'         => (int) $kept['at'],
			'where'      => (string) $kept['where'],
			'serves'     => isset( $kept['serves'] ) ? (bool) $kept['serves'] : null,
			'cloudflare' => ! empty( $kept['cloudflare'] ),
			'refused'    => ! empty( $kept['refused'] ),
		);
	}

	/**
	 * What the settings page shows of the rules while the site serves through them.
	 *
	 * @return array<string, mixed>
	 */
	public static function state(): array {
		/* A block someone edited or took out goes back in, as WordPress's own rules do on the Permalinks screen. */
		self::sync();

		$lines = self::lines();
		$check = self::last_check();

		return array(
			'server'     => self::server(),
			'marker'     => self::marker(),
			'network'    => is_multisite(),
			'where'      => self::where(),
			'file'       => self::file(),
			'lines'      => null === $lines ? null : implode( "\n", self::block( $lines ) ),
			'nginx'      => self::nginx(),
			'places'     => self::places(),
			'serves'     => $check['serves'],
			'refused'    => $check['refused'],
			'cloudflare' => $check['cloudflare'],
			'checked'    => $check['at'],
			'offload'    => Offload_Media::active(),
		);
	}

	/**
	 * Starts the pass over the copies already stale: its first page of each in
	 * this request, so a site whose background never runs still has a library of
	 * that size dealt with, and the rest in the background.
	 */
	public static function start_sweep(): void {
		foreach ( array( 'library', 'folders' ) as $scope ) {
			self::sweep( $scope, 0 );
		}
	}

	/**
	 * One step of the pass: a page of the library's images, or of the files in
	 * other folders, each losing the copies whose file has changed since they were
	 * made, then the next page. It stops once the site serves another way.
	 *
	 * @param mixed $scope `library` or `folders`.
	 * @param mixed $after The id to start after.
	 */
	public static function sweep( $scope, $after ): void {
		if ( ! self::active() ) {
			return;
		}

		$after = (int) $after;
		$ids   = 'folders' === $scope ? ( Folders::available() ? Table::ids( $after, self::SWEEP_PAGE ) : array() ) : Records::recorded( $after, self::SWEEP_PAGE );

		if ( 'folders' !== $scope ) {
			update_meta_cache( 'post', $ids );
		}

		foreach ( $ids as $id ) {
			if ( 'folders' === $scope ) {
				self::stale_folder_file( $id );
			} else {
				Copies::delete_stale( $id );
			}

			$after = $id;
		}

		$next = array( 'folders' === $scope ? 'folders' : 'library', $after );

		if ( self::SWEEP_PAGE === count( $ids ) && function_exists( 'as_schedule_single_action' ) && ! Optimizer::pending( self::SWEEP_HOOK, $next ) ) {
			as_schedule_single_action( time(), self::SWEEP_HOOK, $next, Optimizer::GROUP );
		}
	}

	/**
	 * Takes the copies of a file in another folder off the disk once the file has
	 * changed since they were made: each stands for the one file.
	 *
	 * @param int $id The file's row.
	 * @return bool Whether it had copies that were stale.
	 */
	public static function stale_folder_file( int $id ): bool {
		$row = Table::get( $id );

		if ( null === $row || array() === Copies::own( $row['copies'] ) ) {
			return false;
		}

		$file = Folders::guard( (string) $row['path'] );

		if ( null === $file ) {
			return false;
		}

		clearstatcache( true, $file );

		$bytes = (int) filesize( $file );
		$stale = false;

		foreach ( Copies::own( $row['copies'] ) as $about ) {
			if ( isset( $about['for_bytes'] ) && (int) $about['for_bytes'] !== $bytes ) {
				$stale = true;
			}
		}

		if ( ! $stale ) {
			return false;
		}

		Folder_Restorer::delete_copies( $row );
		Table::update( $id, array( 'copies' => array_diff_key( $row['copies'], Copies::own( $row['copies'] ) ) ) );

		return true;
	}

	/**
	 * This site's marker: the main site's and a single site's `Iminify`, another
	 * site's of a network with its id, each matched as a whole line.
	 */
	public static function marker(): string {
		return is_multisite() && ! is_main_site() ? self::MARKER . ' site ' . get_current_blog_id() : self::MARKER;
	}

	/**
	 * A path's place under the folder the `.htaccess` is in, without slashes at
	 * its ends, or null when it is outside it, is that folder, or holds a
	 * character a rule can't take as it is.
	 *
	 * @param string $absolute An absolute path.
	 */
	protected static function from_home( string $absolute ): ?string {
		$file = self::file();

		if ( null === $file ) {
			return null;
		}

		$home     = dirname( $file ) . '/';
		$absolute = wp_normalize_path( $absolute );

		if ( 0 !== strpos( $absolute . '/', $home ) ) {
			return null;
		}

		$relative = trim( (string) substr( $absolute, strlen( $home ) ), '/' );

		return '' === $relative || ! preg_match( self::SAFE_PATH, $relative ) || false !== strpos( '/' . $relative . '/', '/../' ) ? null : $relative;
	}

	/**
	 * A safe path as a pattern: its dots and pluses taken literally.
	 *
	 * @param string $path The path.
	 */
	protected static function escape( string $path ): string {
		return str_replace( array( '.', '+' ), array( '\.', '\+' ), $path );
	}

	/**
	 * The file's lines without this site's block and the blank line after it, or
	 * null when one of its BEGIN lines has no END line after it (a hand edit took
	 * it out): taking the block out then would take the rest of the file with it,
	 * WordPress's own rules included.
	 *
	 * @param list<string> $lines The file's lines.
	 * @return list<string>|null
	 */
	protected static function without_block( array $lines ): ?array {
		$begin = '# BEGIN ' . self::marker();
		$end   = '# END ' . self::marker();
		$kept  = array();
		$in    = false;
		$after = false;

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );

			if ( ! $in && $begin === $trimmed ) {
				$in = true;
				continue;
			}

			if ( $in ) {
				if ( $end === $trimmed ) {
					$in    = false;
					$after = true;
				}

				continue;
			}

			if ( $after && '' === $trimmed ) {
				$after = false;
				continue;
			}

			$after  = false;
			$kept[] = $line;
		}

		return $in ? null : $kept;
	}

	/**
	 * Whether the lines hold this block as written.
	 *
	 * @param list<string> $lines The file's lines.
	 * @param list<string> $block The block.
	 */
	protected static function has_block( array $lines, array $block ): bool {
		$trimmed = array_map( 'trim', $lines );
		$start   = array_search( $block[0], $trimmed, true );

		return false !== $start && array_slice( $trimmed, (int) $start, count( $block ) ) === array_map( 'trim', $block );
	}

	/**
	 * Whether lines turn rewriting on.
	 *
	 * @param list<string> $lines The lines.
	 */
	protected static function rewrites( array $lines ): bool {
		foreach ( $lines as $line ) {
			if ( preg_match( '/^\s*RewriteEngine\s+on\b/i', $line ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Changes the site's `.htaccess` under the lock WordPress takes when it writes
	 * its own rules there (`Config_File::edit()`), so neither write is lost.
	 *
	 * @param callable(list<string>): ?list<string> $change The file's lines changed, or null to leave it.
	 * @return bool Whether the file now says what the change wanted.
	 */
	protected static function edit( callable $change ): bool {
		$file = self::file();

		return null !== $file && Config_File::edit( $file, $change );
	}

	/**
	 * A file's lines.
	 *
	 * @param string $file The file.
	 * @return list<string>
	 */
	protected static function read( string $file ): array {
		return Config_File::lines( $file );
	}

	/**
	 * The credentials the request was made with, as they came: the settings page's
	 * REST requests carry the site's basic-auth password, which `Loopback` leaves
	 * for the background, and here it goes back to the same host alone.
	 */
	protected static function credentials(): ?string {
		if ( ! isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the request's own credentials, passed on unchanged to the same host, as Site Health's loopback check does.
		return 'Basic ' . base64_encode( wp_unslash( $_SERVER['PHP_AUTH_USER'] ) . ':' . wp_unslash( $_SERVER['PHP_AUTH_PW'] ) );
	}

	/**
	 * One request to the site itself, as a browser accepting these types would make it.
	 *
	 * @param string $url    The address.
	 * @param string $accept The `Accept` header.
	 * @return array<string, mixed>|\WP_Error
	 */
	protected static function ask( string $url, string $accept ) {
		$headers = array( 'Accept' => $accept );

		/* The site's password goes only to the site itself, never to a CDN the uploads folder's address may name. */
		if ( Loopback::is_own( $url ) ) {
			$authorization = Loopback::authorization() ?? self::credentials();

			if ( null !== $authorization ) {
				$headers['Authorization'] = $authorization;
			}
		}

		return wp_remote_get(
			$url,
			array(
				'timeout'     => 3,
				'redirection' => 0,
				'headers'     => $headers,
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter for a request to the site itself.
			)
		);
	}
}
