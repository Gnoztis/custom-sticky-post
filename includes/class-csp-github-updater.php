<?php
/**
 * GitHub updater for Custom Sticky Post.
 *
 * @package CustomStickyPost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin updates through the native WordPress mechanism, sourced from the
 * GitHub releases and tags of Gnoztis/custom-sticky-post.
 *
 * The source of truth is the highest version found in the latest GitHub
 * release and in the tag list, whichever is newer: a tag can be published
 * without a matching release, and then the release alone would never offer an
 * update. WordPress sees the new version, shows it under Plugins and updates
 * from the package of the winning source. No third-party libraries and no
 * write requests to the repository.
 *
 * The key of the update_plugins entry is the runtime plugin_basename() of the
 * installed main file, never a constant: the plugin folder can be renamed by
 * a manual upload or by a GitHub zipball, and WordPress lists only entries
 * whose key matches an installed plugin file.
 */
final class CSP_GitHub_Updater {

	/** Repository that hosts the published versions. */
	private const REPO = 'Gnoztis/custom-sticky-post';

	/** Plugin slug: archive naming only, never the update_plugins key. */
	private const SLUG = 'custom-sticky-post';

	/** Successful GitHub answer is cached for six hours. */
	private const CACHE_TTL = 21600;

	/** A completely failed check is retried after fifteen minutes. */
	private const ERROR_TTL = 900;

	private const UPDATE_TRANSIENT = 'csp_github_release';

	private const UPDATE_ERROR_TRANSIENT = 'csp_github_release_error';

	/** @var array{requires:string,requires_php:string,tested:string}|null */
	private static ?array $header = null;

	/**
	 * Hooks the updater into the WordPress update pipeline.
	 */
	public function register_hooks(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'rename_source' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'after_upgrade' ), 10, 2 );
		add_action( 'load-plugins.php', array( $this, 'maybe_force_check' ) );
		add_filter( 'plugin_action_links_' . self::basename(), array( $this, 'action_links' ) );
	}

	/* ============================================================
	 *  Installed location
	 * ============================================================ */

	/**
	 * Basename of the installed main plugin file, e.g.
	 * "custom-sticky-post/custom-sticky-post.php".
	 *
	 * Computed at runtime, never taken from a constant: the folder is whatever
	 * the site has. WordPress keys update_plugins->response by this basename
	 * and counts every entry for the admin badge, but lists only entries whose
	 * key matches a file from get_plugins() — so a hard-coded key shows an
	 * "update available" badge with an empty update list.
	 */
	public static function basename(): string {
		$basename = plugin_basename( CUSTOM_STICKY_PLUGIN_FILE );

		if ( '' === $basename ) {
			// An empty result means the main file is not inside the plugin
			// directory; fall back to the documented layout.
			$basename = self::SLUG . '/' . wp_basename( CUSTOM_STICKY_PLUGIN_FILE );
		}

		return $basename;
	}

	/**
	 * Folder name of the installed plugin, used for the "slug" field and for
	 * matching the plugin information request. Falls back to SLUG when the
	 * main file sits directly in the plugins directory.
	 */
	private static function dirname(): string {
		$folder = dirname( self::basename() );

		return ( '.' === $folder || '' === $folder ) ? self::SLUG : $folder;
	}

	/* ============================================================
	 *  Update plumbing
	 * ============================================================ */

	/**
	 * Adds our entry to the update_plugins transient when GitHub has a newer
	 * version than the installed one.
	 *
	 * @param mixed $transient
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		$basename  = self::basename();
		$installed = $this->installed_version();

		// Runs before the early returns: a dangling entry is a leftover of the
		// previous key and must disappear even when GitHub is unreachable now.
		$this->purge_stale_entries( $transient, $basename );

		// The entry offers what is already on disk: drop it, so a phantom left
		// by the request that installed the update disappears on this run.
		$this->purge_installed_entry( $transient, $basename, $installed );

		$release = $this->get_release();
		if ( null === $release ) {
			return $transient;
		}

		// Inside the request that replaces the files the constant still holds
		// the version loaded at request start, so the release must be newer
		// than both the constant and the header on disk.
		if ( ! version_compare( $release['version'], CUSTOM_STICKY_VERSION, '>' ) ) {
			return $transient;
		}
		if ( ! version_compare( $release['version'], $installed, '>' ) ) {
			return $transient;
		}

		$transient->response[ $basename ] = (object) array(
			'id'            => $basename,
			'slug'          => self::dirname(),
			'plugin'        => $basename,
			'new_version'   => $release['version'],
			'url'           => 'https://github.com/' . self::REPO,
			'package'       => $release['package'],
			'tested'        => $release['tested'],
			'requires'      => $release['requires'],
			'requires_php'  => $release['requires_php'],
			'icons'         => array(),
			'banners'       => array(),
			'compatibility' => new stdClass(),
		);

		if ( isset( $transient->no_update ) && is_array( $transient->no_update ) ) {
			unset( $transient->no_update[ $basename ] );
		}

		return $transient;
	}

	/**
	 * Drops update entries that point at our main file under a foreign key.
	 *
	 * Such entries are leftovers of a hard-coded basename or of a folder that
	 * was renamed. They make the admin badge count an update that
	 * get_plugin_updates() cannot list, because the key does not match any
	 * installed plugin file.
	 *
	 * @param mixed  $transient
	 * @param string $basename Our real basename, kept as is.
	 */
	private function purge_stale_entries( $transient, string $basename ): void {
		$main = wp_basename( CUSTOM_STICKY_PLUGIN_FILE );

		foreach ( array( 'response', 'no_update' ) as $bucket ) {
			if ( ! isset( $transient->$bucket ) || ! is_array( $transient->$bucket ) ) {
				continue;
			}

			foreach ( $transient->$bucket as $key => $entry ) {
				if ( (string) $key === $basename || ! is_object( $entry ) ) {
					continue;
				}
				if ( empty( $entry->plugin ) || wp_basename( (string) $entry->plugin ) !== $main ) {
					continue;
				}

				unset( $transient->$bucket[ $key ] );
			}
		}
	}

	/**
	 * Version of the plugin that is actually on disk.
	 *
	 * CUSTOM_STICKY_VERSION is defined when the main file loads: inside the
	 * request that installs an update it still holds the previous version
	 * while the files already contain the new one. The header is the source of
	 * truth; the constant is only a fallback for an unreadable file.
	 *
	 * Not cached on purpose: the value has to be re-read after the upgrader
	 * replaces the files, and a per-request cache would keep the stale one.
	 */
	private function installed_version(): string {
		$data = function_exists( 'get_file_data' )
			? get_file_data( CUSTOM_STICKY_PLUGIN_FILE, array( 'Version' => 'Version' ), 'plugin' )
			: array();

		$version = isset( $data['Version'] ) ? trim( (string) $data['Version'] ) : '';

		return '' === $version ? CUSTOM_STICKY_VERSION : $version;
	}

	/**
	 * Drops our own response entry when it offers an already installed version.
	 *
	 * Core rewrites the update_plugins transient in the request that replaces
	 * the files. An entry built there from the in-memory constant advertises
	 * the version that has just been installed, so the site offers the same
	 * update a second time; it would stay for up to twelve hours.
	 *
	 * @param mixed  $transient
	 * @param string $basename  Our key in the transient.
	 * @param string $installed Version from the plugin header on disk.
	 */
	private function purge_installed_entry( $transient, string $basename, string $installed ): void {
		if ( ! isset( $transient->response[ $basename ] ) || ! is_object( $transient->response[ $basename ] ) ) {
			return;
		}

		$entry   = $transient->response[ $basename ];
		$offered = isset( $entry->new_version ) ? trim( (string) $entry->new_version ) : '';

		if ( '' !== $offered && version_compare( $offered, $installed, '>' ) ) {
			// Not newer than the constant either — the same phantom, seen from
			// the other side.
			if ( version_compare( $offered, CUSTOM_STICKY_VERSION, '>' ) ) {
				return;
			}
		}

		// Not moved to no_update: a response-shaped entry carries a package
		// URL, and core builds no_update itself.
		unset( $transient->response[ $basename ] );
	}

	/**
	 * Data for the View plugin details modal.
	 *
	 * @param mixed  $result
	 * @param string $action
	 * @param mixed  $args
	 * @return mixed
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) ) {
			return $result;
		}
		if ( self::dirname() !== (string) $args->slug ) {
			return $result;
		}

		$release = $this->get_release();
		if ( null === $release ) {
			return $result;
		}

		$header = $this->header_data();

		return (object) array(
			'name'          => 'Custom Sticky Post',
			'slug'          => self::dirname(),
			'version'       => $release['version'],
			'author'        => '<a href="https://github.com/Gnoztis">Gnostis</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => $header['requires'],
			'tested'        => $this->tested_version( $header['tested'] ),
			'requires_php'  => $header['requires_php'],
			'download_link' => $release['package'],
			'last_updated'  => $release['published_at'],
			'sections'      => array(
				'description' => __( 'Один закреплённый пост в начале главной страницы: флажок в метабоксе записи, отдельное оформление и скрытая миниатюра. Управление доступно только администраторам.', 'custom-sticky-post' ),
				'changelog'   => '' !== $release['body'] ? wpautop( $release['body'] ) : __( 'Описание изменений в релизе не заполнено.', 'custom-sticky-post' ),
			),
		);
	}

	/**
	 * GitHub archives unpack into a directory like
	 * Gnoztis-custom-sticky-post-1a2b3c4 — rename it to the directory the site
	 * actually uses (the folder of the installed main file), otherwise
	 * WordPress updates the wrong directory or adds a second copy.
	 *
	 * @param string $source
	 * @param string $remote_source
	 * @param mixed  $upgrader
	 * @param array  $hook_extra
	 * @return string
	 */
	public function rename_source( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( ! is_string( $source ) || ! is_string( $remote_source ) ) {
			return $source;
		}
		if ( ! is_array( $hook_extra ) || empty( $hook_extra['plugin'] ) ) {
			return $source;
		}
		if ( self::basename() !== (string) $hook_extra['plugin'] ) {
			return $source;
		}
		if ( ! is_dir( $source ) ) {
			return $source;
		}
		if ( ! is_file( trailingslashit( $source ) . wp_basename( CUSTOM_STICKY_PLUGIN_FILE ) ) ) {
			return $source;
		}

		// Rename the unpacked directory to the folder the site actually uses,
		// not to the slug: the update has to replace the installed directory,
		// otherwise WordPress ends up with two copies of the plugin.
		$folder = dirname( self::basename() );
		if ( '.' === $folder || '' === $folder ) {
			// The main file sits directly in the plugins directory, so there
			// is no directory to rename.
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . $folder;
		if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
			return $source;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		if ( ! $wp_filesystem ) {
			return $source;
		}

		if ( $wp_filesystem->move( $source, $desired, true ) ) {
			return trailingslashit( $desired );
		}

		return $source;
	}

	/**
	 * Reset the update-check cache after an upgrade so the site does not keep
	 * advertising the version it has just installed.
	 *
	 * @param mixed $upgrader
	 * @param array $hook_extra
	 */
	public function after_upgrade( $upgrader = null, $hook_extra = array() ): void {
		$hook_extra = is_array( $hook_extra ) ? $hook_extra : array();

		if ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			if ( ! in_array( self::basename(), $hook_extra['plugins'], true ) ) {
				return;
			}
		} elseif ( ! empty( $hook_extra['plugin'] ) && self::basename() !== (string) $hook_extra['plugin'] ) {
			return;
		}

		// The files and their headers have just been replaced: forget the
		// parsed header values and the cached release check, so the next run
		// does not advertise the version that is now installed.
		self::$header = null;
		$this->flush();
	}

	/**
	 * Check for updates link in the plugin row.
	 *
	 * @param string[] $links
	 * @return string[]
	 */
	public function action_links( $links ): array {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return (array) $links;
		}

		$url = wp_nonce_url(
			add_query_arg( 'csp-force-check', '1', admin_url( 'plugins.php' ) ),
			'csp_force_check'
		);

		$links              = (array) $links;
		$links['csp_check'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Проверить обновления', 'custom-sticky-post' ) . '</a>';

		return $links;
	}

	/**
	 * Handles the Check for updates click on plugins.php.
	 */
	public function maybe_force_check(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce is verified below.
		if ( ! isset( $_GET['csp-force-check'] ) ) {
			return;
		}
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		check_admin_referer( 'csp_force_check' );

		$this->flush();

		require_once ABSPATH . 'wp-admin/includes/update.php';
		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}

		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}

	/**
	 * Drops every cached value of this class.
	 *
	 * Called after an upgrade, so the next run does not reuse the release data
	 * (and the header values) read before the files were replaced.
	 */
	private function flush(): void {
		delete_transient( self::UPDATE_TRANSIENT );
		delete_transient( self::UPDATE_ERROR_TRANSIENT );
		delete_site_transient( 'update_plugins' );

		self::$header = null;
	}

	/* ============================================================
	 *  GitHub API
	 * ============================================================ */

	/**
	 * Latest version data (6 hour cache, 15 minute error cache).
	 *
	 * @return array<string,mixed>|null
	 */
	private function get_release(): ?array {
		$cached = get_transient( self::UPDATE_TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['version'], $cached['package'] ) ) {
			return $cached;
		}

		if ( get_transient( self::UPDATE_ERROR_TRANSIENT ) ) {
			return null;
		}

		$release = $this->fetch_latest();

		if ( null === $release ) {
			set_transient( self::UPDATE_ERROR_TRANSIENT, 1, self::ERROR_TTL );
			return null;
		}

		set_transient( self::UPDATE_TRANSIENT, $release, self::CACHE_TTL );

		return $release;
	}

	/**
	 * Picks the higher of the two sources.
	 *
	 * Both requests are always made: a repository can keep an older release
	 * while a newer tag already exists, and "release first, otherwise tag"
	 * would never offer that tag. On equal versions the release wins, because
	 * it carries the release notes.
	 *
	 * @return array<string,mixed>|null
	 */
	private function fetch_latest(): ?array {
		$release = $this->fetch_latest_release();
		$tag     = $this->fetch_latest_tag();

		if ( null === $release ) {
			return $tag;
		}
		if ( null === $tag ) {
			return $release;
		}

		return version_compare( $tag['version'], $release['version'], '>' ) ? $tag : $release;
	}

	/**
	 * Candidate from the latest GitHub release, with the package of that
	 * release.
	 *
	 * @return array<string,mixed>|null
	 */
	private function fetch_latest_release(): ?array {
		$body = $this->api_get( sprintf( 'https://api.github.com/repos/%s/releases/latest', self::REPO ) );
		if ( null === $body ) {
			return null;
		}

		$version = $this->normalize_version( isset( $body['tag_name'] ) ? (string) $body['tag_name'] : '' );
		if ( '' === $version ) {
			return null;
		}

		$package = $this->find_package( $body );
		if ( '' === $package ) {
			return null;
		}

		return $this->build_release(
			$version,
			$package,
			isset( $body['body'] ) ? (string) $body['body'] : '',
			isset( $body['published_at'] ) ? (string) $body['published_at'] : ''
		);
	}

	/**
	 * Candidate from the tag list: the highest semver tag and its zipball.
	 *
	 * @return array<string,mixed>|null
	 */
	private function fetch_latest_tag(): ?array {
		$tags = $this->api_get( sprintf( 'https://api.github.com/repos/%s/tags?per_page=30', self::REPO ) );
		if ( ! is_array( $tags ) ) {
			return null;
		}

		$best_version = '';
		$best_tag     = '';

		foreach ( $tags as $tag ) {
			if ( ! is_array( $tag ) || empty( $tag['name'] ) ) {
				continue;
			}
			$version = $this->normalize_version( (string) $tag['name'] );
			if ( '' === $version ) {
				continue;
			}
			if ( '' === $best_version || version_compare( $version, $best_version, '>' ) ) {
				$best_version = $version;
				$best_tag     = (string) $tag['name'];
			}
		}

		if ( '' === $best_version || '' === $best_tag ) {
			return null;
		}

		$package = $this->safe_package_url(
			sprintf(
				'https://api.github.com/repos/%s/zipball/%s',
				self::REPO,
				rawurlencode( $best_tag )
			)
		);

		if ( '' === $package ) {
			return null;
		}

		return $this->build_release( $best_version, $package, '', '' );
	}

	/**
	 * Normalises a GitHub answer into the shape the update pipeline expects.
	 *
	 * @param string $version
	 * @param string $package
	 * @param string $body
	 * @param string $published_at
	 * @return array<string,mixed>
	 */
	private function build_release( string $version, string $package, string $body, string $published_at ): array {
		$header = $this->header_data();

		return array(
			'version'      => $version,
			'package'      => $package,
			'body'         => $body,
			'published_at' => $published_at,
			'tested'       => $this->tested_version( $header['tested'] ),
			'requires'     => $header['requires'],
			'requires_php' => $header['requires_php'],
		);
	}

	/**
	 * "Tested up to" turned into the value WordPress compares with.
	 *
	 * The header holds a branch claim ("7.1"), while the update screen compares
	 * the stored value with the full running version ("7.1.5"):
	 * `version_compare( $update->tested, $cur_wp_version, '>=' )`. A branch
	 * claim is always lower than a patch release, which is why a bare "7.1"
	 * still renders "Compatibility with WordPress 7.1.5: Not tested".
	 *
	 * api.wordpress.org resolves this by serving the patch release of the
	 * declared branch: readmes say "Tested up to: 7.1" and the API answers
	 * "7.1.5". The same expansion is done here, so the claim covers the running
	 * patch release of the branch the author declared.
	 *
	 * @param string $tested Header value, e.g. "7.1"; empty when absent.
	 * @return string Empty when the header is absent, otherwise a version.
	 */
	private function tested_version( string $tested ): string {
		$tested = trim( $tested );
		if ( '' === $tested ) {
			return '';
		}

		$running = function_exists( 'wp_get_wp_version' ) ? (string) wp_get_wp_version() : '';
		if ( '' === $running && isset( $GLOBALS['wp_version'] ) ) {
			$running = (string) $GLOBALS['wp_version'];
		}
		$running = trim( (string) preg_replace( '/-.*/', '', $running ) );
		if ( '' === $running ) {
			return $tested;
		}

		$tested_parts  = explode( '.', $tested );
		$running_parts = explode( '.', $running );

		// Already as specific as the running version, or more so.
		if ( count( $tested_parts ) >= count( $running_parts ) ) {
			return $tested;
		}

		// Same branch: the claim covers the running patch release as well.
		if ( array_slice( $running_parts, 0, count( $tested_parts ) ) === $tested_parts ) {
			return $running;
		}

		return $tested;
	}

	/**
	 * GET to the GitHub API with our User-Agent and an optional token.
	 *
	 * @return array<string,mixed>|null
	 */
	private function api_get( string $url ): ?array {
		$args = array(
			'timeout'     => 5,
			'redirection' => 2,
			'headers'     => array(
				'Accept'       => 'application/vnd.github+json',
				'User-Agent'   => self::SLUG . '/' . CUSTOM_STICKY_VERSION,
				'Content-Type' => 'application/json',
			),
		);

		$token = $this->token();
		if ( '' !== $token ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}

		// The URL is built from the repository constant, not from user input;
		// scheme and host of the answer are validated separately.
		$response = wp_remote_get( $url, $args ); // phpcs:ignore -- URL built from a constant.
		if ( is_wp_error( $response ) ) {
			return null;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $body ) ? $body : null;
	}

	/**
	 * Archive URL: the release zip asset first, then the GitHub zipball.
	 *
	 * @param array<string,mixed> $body
	 */
	private function find_package( array $body ): string {
		if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( ! is_array( $asset ) || empty( $asset['browser_download_url'] ) ) {
					continue;
				}
				$name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
				if ( ! preg_match( '/\.zip$/i', $name ) ) {
					continue;
				}
				$url = $this->safe_package_url( (string) $asset['browser_download_url'] );
				if ( '' !== $url ) {
					return $url;
				}
			}
		}

		if ( ! empty( $body['zipball_url'] ) ) {
			return $this->safe_package_url( (string) $body['zipball_url'] );
		}

		return '';
	}

	/**
	 * HTTPS only and GitHub hosts only — the package must not come from a
	 * field a third-party service could tamper with.
	 */
	private function safe_package_url( string $url ): string {
		$url = esc_url_raw( trim( $url ) );
		if ( '' === $url ) {
			return '';
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $url, PHP_URL_HOST );

		if ( 'https' !== strtolower( (string) $scheme ) ) {
			return '';
		}

		$allowed = array( 'api.github.com', 'github.com', 'codeload.github.com', 'objects.githubusercontent.com' );
		if ( ! in_array( strtolower( (string) $host ), $allowed, true ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * Strict semver: v1.2, 1.2.3 and v1.2.3 are accepted; pre-releases and
	 * four-part versions are rejected.
	 */
	private function normalize_version( string $tag ): string {
		$tag = trim( $tag );
		if ( ! preg_match( '/^v?(\d+\.\d+(?:\.\d+)?)$/', $tag, $m ) ) {
			return '';
		}
		return $m[1];
	}

	/**
	 * GitHub token from wp-config.php or a filter, for API rate limits.
	 */
	private function token(): string {
		$token = defined( 'CSP_GITHUB_TOKEN' ) ? (string) CSP_GITHUB_TOKEN : '';
		$token = (string) apply_filters( 'csp_github_token', $token );

		return trim( $token );
	}

	/**
	 * Requires / Requires PHP / Tested up to from the plugin header.
	 *
	 * "Tested up to" is a separate field: WordPress compares it with its own
	 * version on the update screen, while "Requires at least" is the minimum
	 * supported version. An absent header yields an empty string, never a copy
	 * of another field.
	 *
	 * @return array{requires:string,requires_php:string,tested:string}
	 */
	private function header_data(): array {
		if ( null === self::$header ) {
			$data = function_exists( 'get_file_data' )
				? get_file_data(
					CUSTOM_STICKY_PLUGIN_FILE,
					array(
						'Requires at least' => 'Requires at least',
						'Requires PHP'      => 'Requires PHP',
						'Tested up to'      => 'Tested up to',
					),
					'plugin'
				)
				: array();

			self::$header = array(
				'requires'     => isset( $data['Requires at least'] ) ? (string) $data['Requires at least'] : '',
				'requires_php' => isset( $data['Requires PHP'] ) ? (string) $data['Requires PHP'] : '',
				'tested'       => isset( $data['Tested up to'] ) ? (string) $data['Tested up to'] : '',
			);
		}

		return self::$header;
	}
}
