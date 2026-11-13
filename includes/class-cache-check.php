<?php
defined( 'ABSPATH' ) || exit;

/**
 * Cache check (Visigate → Cache Check).
 *
 * Explains how page caching interacts with visibility rules on this site:
 *  - which page-cache plugins and hosts were detected, and whether they honor Visigate's
 *    "do not cache this page" signal (the DONOTCACHEPAGE constant, LiteSpeed's API, no-cache headers)
 *  - which rule sets and pages use visitor-specific conditions (device, time, cookies…), the ones a
 *    shared cached copy can get wrong
 *  - an on-demand header test that loads a page twice as a logged-out visitor
 */
class Visigate_Cache_Check {

	const SLUG = 'visigate-cache';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 11 );
		add_action( 'admin_post_visigate_cache_test', array( __CLASS__, 'handle_test' ) );
	}

	public static function page_url() {
		return add_query_arg( array( 'post_type' => Visigate_Ruleset::CPT, 'page' => self::SLUG ), admin_url( 'edit.php' ) );
	}

	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=' . Visigate_Ruleset::CPT,
			__( 'Cache Check', 'visigate' ),
			__( 'Cache Check', 'visigate' ),
			visigate_capability(),
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/* ------------------------------ detection ------------------------------ */

	/**
	 * Page caches found on this site. Each: name, kind (plugin|host|edge), honors (yes|headers|unknown), note.
	 */
	public static function detect() {
		$found = array();
		$add   = function ( $name, $kind, $honors, $note = '' ) use ( &$found ) {
			$found[] = array( 'name' => $name, 'kind' => $kind, 'honors' => $honors, 'note' => $note );
		};

		if ( defined( 'WP_ROCKET_VERSION' ) ) {
			$add( 'WP Rocket', 'plugin', 'yes' );
		}
		if ( defined( 'LSCWP_V' ) || defined( 'LSCWP_DIR' ) ) {
			$add( 'LiteSpeed Cache', 'plugin', 'yes', __( 'Visigate also tells LiteSpeed directly through its API.', 'visigate' ) );
		}
		if ( defined( 'W3TC' ) ) {
			$add( 'W3 Total Cache', 'plugin', 'yes' );
		}
		if ( defined( 'WPCACHEHOME' ) || function_exists( 'wp_cache_phase2' ) ) {
			$add( 'WP Super Cache', 'plugin', 'yes', __( 'In "Expert" (mod_rewrite) mode, cached files are served before WordPress runs; pages already cached before a rule was added keep being served until the cache is cleared.', 'visigate' ) );
		}
		if ( class_exists( 'WpFastestCache' ) ) {
			$add( 'WP Fastest Cache', 'plugin', 'yes' );
		}
		if ( class_exists( 'Cache_Enabler' ) ) {
			$add( 'Cache Enabler', 'plugin', 'yes' );
		}
		if ( defined( 'WPHB_VERSION' ) ) {
			$add( 'Hummingbird', 'plugin', 'yes' );
		}
		if ( class_exists( 'WP_Optimize' ) || defined( 'WPO_VERSION' ) ) {
			$add( 'WP-Optimize', 'plugin', 'yes' );
		}
		if ( defined( 'BREEZE_VERSION' ) ) {
			$add( 'Breeze (Cloudways)', 'host', 'headers', __( 'Breeze honors the signal, but Cloudways\' Varnish server cache only reads headers. Exclude affected URLs in Breeze → Varnish if needed.', 'visigate' ) );
		}
		if ( defined( 'SiteGround_Optimizer\VERSION' ) || class_exists( 'SiteGround_Optimizer\Loader\Loader' ) ) {
			$add( 'SiteGround Speed Optimizer', 'host', 'headers', __( 'SiteGround\'s Dynamic Cache runs on the server. Exclude affected URLs under Speed Optimizer → Caching if a page shows the wrong content.', 'visigate' ) );
		}
		if ( class_exists( 'Nginx_Helper' ) ) {
			$add( 'Nginx Helper (server cache)', 'host', 'headers' );
		}
		if ( class_exists( 'WpeCommon' ) || defined( 'WPE_APIKEY' ) ) {
			$add( 'WP Engine', 'host', 'headers', __( 'WP Engine caches pages for logged-out visitors at the server. Ask support to exclude affected URLs, or use URLs that differ per audience.', 'visigate' ) );
		}
		if ( defined( 'KINSTAMU_VERSION' ) || defined( 'KINSTA_CACHE_ZONE' ) ) {
			$add( 'Kinsta', 'host', 'headers', __( 'Kinsta caches at the server. Add cache exclusions under MyKinsta → Tools → Site cache if a page shows the wrong content.', 'visigate' ) );
		}
		if ( getenv( 'PANTHEON_ENVIRONMENT' ) || isset( $_ENV['PANTHEON_ENVIRONMENT'] ) ) {
			$add( 'Pantheon', 'host', 'headers' );
		}
		if ( defined( 'FLYWHEEL_CONFIG_DIR' ) ) {
			$add( 'Flywheel', 'host', 'headers' );
		}
		if ( class_exists( 'WPaaS\Plugin' ) ) {
			$add( 'GoDaddy Managed WordPress', 'host', 'headers' );
		}
		if ( defined( 'IS_ATOMIC' ) || defined( 'ATOMIC_SITE_ID' ) || defined( 'IS_WPCOM' ) ) {
			$add( 'WordPress.com', 'host', 'headers' );
		}
		if ( defined( 'CLOUDFLARE_PLUGIN_DIR' ) ) {
			$add( 'Cloudflare', 'edge', 'headers', __( 'With Automatic Platform Optimization (APO) or "Cache Everything", Cloudflare serves cached pages from its edge. It respects no-cache headers but not the DONOTCACHEPAGE signal.', 'visigate' ) );
		}
		return apply_filters( 'visigate_cache_detect', $found );
	}

	/** Visitor-specific condition types (the ones that mark a page as not cacheable): type => label. */
	public static function dynamic_types() {
		$out = array();
		foreach ( Visigate_Conditions::all() as $type => $def ) {
			if ( ! empty( $def['dynamic'] ) ) {
				$out[ $type ] = $def['label'];
			}
		}
		return $out;
	}

	/** Visitor-specific condition types used in a rules array. */
	private static function types_in_rules( $rules, $dynamic ) {
		$found = array();
		if ( is_string( $rules ) ) {
			$rules = json_decode( $rules, true );
		}
		if ( ! is_array( $rules ) || ! empty( $rules['always_hide'] ) ) {
			return $found;
		}
		foreach ( (array) ( $rules['groups'] ?? array() ) as $g ) {
			foreach ( (array) ( $g['conditions'] ?? array() ) as $c ) {
				if ( ! is_array( $c ) || ! Visigate_Conditions::is_complete( $c ) ) {
					continue;
				}
				$type = (string) ( $c['type'] ?? '' );
				if ( isset( $dynamic[ $type ] ) ) {
					$found[ $type ] = $dynamic[ $type ];
				}
			}
		}
		return $found;
	}

	/** Visitor-specific condition types in parsed blocks (visigateRules attributes), recursively. */
	private static function types_in_blocks( $blocks, $dynamic, &$types ) {
		foreach ( (array) $blocks as $block ) {
			if ( ! empty( $block['attrs']['visigateRules'] ) ) {
				$types += self::types_in_rules( $block['attrs']['visigateRules'], $dynamic );
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				self::types_in_blocks( $block['innerBlocks'], $dynamic, $types );
			}
		}
	}

	/** Visitor-specific condition types in Elementor data (classic JSON strings and atomic typed props), recursively. */
	private static function types_in_elementor( $data, $dynamic, &$types ) {
		if ( ! is_array( $data ) ) {
			return;
		}
		foreach ( $data as $key => $value ) {
			if ( 'visigate_rules' === $key ) {
				if ( is_array( $value ) && isset( $value['$$type'] ) ) {
					if ( ! empty( $value['disabled'] ) ) {
						continue;
					}
					$value = $value['value'] ?? '';
				}
				$types += self::types_in_rules( $value, $dynamic );
			} elseif ( is_array( $value ) ) {
				self::types_in_elementor( $value, $dynamic, $types );
			}
		}
	}

	/** Rule sets with visitor-specific conditions: array of { post, types }. */
	public static function affected_sets() {
		$dynamic = self::dynamic_types();
		$out     = array();
		$sets    = get_posts(
			array(
				'post_type'   => Visigate_Ruleset::CPT,
				'post_status' => 'publish',
				'numberposts' => 500,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);
		foreach ( $sets as $set ) {
			$types = self::types_in_rules( get_post_meta( $set->ID, Visigate_Ruleset::META, true ), $dynamic );
			if ( $types ) {
				$out[] = array( 'post' => $set, 'types' => $types );
			}
		}
		return $out;
	}

	/** Published content whose own (inline) rules use visitor-specific conditions: array of { post, types }. */
	public static function affected_content( $limit = 50 ) {
		global $wpdb;
		$dynamic = self::dynamic_types();
		if ( ! $dynamic ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type NOT IN (%s, %s) AND post_content LIKE %s ORDER BY post_modified DESC LIMIT 400", 'revision', Visigate_Ruleset::CPT, '%visigateRules%' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$el  = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID, pm.meta_value AS post_content FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_elementor_data' AND p.post_status = 'publish' AND pm.meta_value LIKE %s ORDER BY p.post_modified DESC LIMIT 400", '%visigate\_rules%' ) );
		$out = array();
		$add = function ( $id, $types ) use ( &$out ) {
			if ( ! $types ) {
				return;
			}
			if ( ! isset( $out[ $id ] ) ) {
				$post = get_post( $id );
				if ( ! $post ) {
					return;
				}
				$out[ $id ] = array( 'post' => $post, 'types' => array() );
			}
			$out[ $id ]['types'] += $types;
		};
		foreach ( (array) $rows as $row ) {
			$types = array();
			self::types_in_blocks( parse_blocks( (string) $row->post_content ), $dynamic, $types );
			$add( (int) $row->ID, $types );
			if ( count( $out ) >= $limit ) {
				return array_values( $out );
			}
		}
		foreach ( (array) $el as $row ) {
			$types = array();
			self::types_in_elementor( json_decode( (string) $row->post_content, true ), $dynamic, $types );
			$add( (int) $row->ID, $types );
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return array_values( $out );
	}

	/* --------------------------------- test --------------------------------- */

	private static function transient_key() {
		return 'visigate_cache_test_' . get_current_user_id();
	}

	/** Response headers that tell whether a cache served the page. */
	private static function cache_headers() {
		return array( 'cache-control', 'age', 'expires', 'x-cache', 'x-cache-status', 'x-cache-hits', 'cf-cache-status', 'x-litespeed-cache', 'x-litespeed-cache-control', 'x-proxy-cache', 'x-kinsta-cache', 'x-sg-cache', 'x-varnish', 'x-wpe-cached', 'x-rocket-nginx-serving-static', 'x-nginx-cache', 'x-powered-by', 'x-cache-enabled', 'x-ac', 'x-wp-cf-super-cache', 'server', 'via' );
	}

	public static function handle_test() {
		check_admin_referer( 'visigate_cache_test' );
		if ( ! current_user_can( visigate_capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'visigate' ), '', array( 'response' => 403 ) );
		}
		$url  = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$url  = '' !== $url ? $url : home_url( '/' );
		$home = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		if ( wp_parse_url( $url, PHP_URL_HOST ) !== $home ) {
			set_transient( self::transient_key(), array( 'url' => $url, 'error' => __( 'Only pages on this site can be tested.', 'visigate' ) ), 15 * MINUTE_IN_SECONDS );
			wp_safe_redirect( self::page_url() );
			exit;
		}
		$runs = array();
		for ( $i = 0; $i < 2; $i++ ) {
			// No cookies are sent, so the page is fetched as a logged-out visitor.
			$res = wp_remote_get(
				$url,
				array(
					'timeout'     => 15,
					'redirection' => 3,
					'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter for loopback requests.
					'user-agent'  => 'Mozilla/5.0 (Visigate cache check; ' . home_url( '/' ) . ')',
				)
			);
			if ( is_wp_error( $res ) ) {
				$runs[] = array( 'error' => $res->get_error_message() );
				continue;
			}
			$headers = array();
			foreach ( self::cache_headers() as $h ) {
				$v = wp_remote_retrieve_header( $res, $h );
				if ( '' !== $v && array() !== $v ) {
					$headers[ $h ] = is_array( $v ) ? implode( ', ', $v ) : (string) $v;
				}
			}
			$runs[] = array( 'code' => (int) wp_remote_retrieve_response_code( $res ), 'headers' => $headers );
		}
		set_transient( self::transient_key(), array( 'url' => $url, 'runs' => $runs, 'time' => time() ), 15 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/** Did a response come from a cache? true, false, or null when the headers do not say. */
	public static function looks_cached( $headers ) {
		$headers = array_change_key_case( (array) $headers );
		foreach ( array( 'x-cache', 'x-cache-status', 'cf-cache-status', 'x-litespeed-cache', 'x-proxy-cache', 'x-kinsta-cache', 'x-sg-cache', 'x-nginx-cache', 'x-ac' ) as $h ) {
			if ( isset( $headers[ $h ] ) ) {
				$v = strtolower( $headers[ $h ] );
				if ( false !== strpos( $v, 'hit' ) ) {
					return true;
				}
				if ( false !== strpos( $v, 'miss' ) || false !== strpos( $v, 'bypass' ) || false !== strpos( $v, 'dynamic' ) || false !== strpos( $v, 'expired' ) ) {
					return false;
				}
			}
		}
		if ( isset( $headers['x-wpe-cached'] ) || isset( $headers['x-rocket-nginx-serving-static'] ) ) {
			return true;
		}
		if ( isset( $headers['age'] ) && (int) $headers['age'] > 0 ) {
			return true;
		}
		return null;
	}

	/* --------------------------------- page --------------------------------- */

	public static function render_page() {
		if ( ! current_user_can( visigate_capability() ) ) {
			return;
		}
		$caches   = self::detect();
		$auto     = (bool) Visigate_Admin::opt( 'auto_nocache' );
		$sets     = self::affected_sets();
		$content  = self::affected_content();
		$test     = get_transient( self::transient_key() );
		$wp_cache = defined( 'WP_CACHE' ) && WP_CACHE;
		$dropin   = file_exists( WP_CONTENT_DIR . '/advanced-cache.php' );
		$block    = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
		$honors   = array(
			'yes'     => __( 'Yes', 'visigate' ),
			'headers' => __( 'Only through headers', 'visigate' ),
			'unknown' => __( 'Unknown', 'visigate' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Cache Check', 'visigate' ); ?></h1>
			<p style="max-width:780px"><?php esc_html_e( 'Visibility rules run on the server for each visit. A page cache stores one copy of a page and serves it to many visitors, so rules that depend on the visitor (device, time, cookies, URL parameters, cart and similar) can show the wrong content from a cached copy. Login and role rules are usually safe because page caches skip logged-in visitors.', 'visigate' ); ?></p>

			<h2><?php esc_html_e( 'Status', 'visigate' ); ?></h2>
			<table class="widefat striped" style="max-width:900px">
				<tbody>
					<tr>
						<td style="width:280px"><strong><?php esc_html_e( 'Automatic cache exclusion', 'visigate' ); ?></strong></td>
						<td>
							<?php if ( $auto ) : ?>
								<span style="color:#00701a">✓ <?php esc_html_e( 'On. Pages that run visitor-specific rules tell caches not to store them.', 'visigate' ); ?></span>
							<?php else : ?>
								<span style="color:#b32d2e">✗ <?php esc_html_e( 'Off. Turn it on in Settings unless you manage cache exclusions yourself.', 'visigate' ); ?></span>
							<?php endif; ?>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Visigate_Ruleset::CPT . '&page=visigate-settings' ) ); ?>"><?php esc_html_e( 'Settings', 'visigate' ); ?></a>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Page caching in WordPress', 'visigate' ); ?></strong></td>
						<td>
							<?php
							if ( $wp_cache || $dropin ) {
								esc_html_e( 'A page cache is enabled (WP_CACHE / advanced-cache.php).', 'visigate' );
							} else {
								esc_html_e( 'No WordPress page-cache drop-in found. Your host or a CDN may still cache pages; run the header test below to check.', 'visigate' );
							}
							?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Theme type', 'visigate' ); ?></strong></td>
						<td>
							<?php
							echo esc_html(
								$block
									? __( 'Block theme: rules run before any output, so Visigate can also send no-cache headers that server and CDN caches understand.', 'visigate' )
									: __( 'Classic theme: rules usually run after headers are sent, so server and CDN caches (Varnish, Nginx, Cloudflare) do not receive a no-cache header. Plugin caches still honor the signal.', 'visigate' )
							);
							?>
						</td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Detected caches', 'visigate' ); ?></h2>
			<?php if ( ! $caches ) : ?>
				<p><?php esc_html_e( 'No known cache plugin or managed host was detected.', 'visigate' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:900px">
					<thead><tr>
						<th style="width:280px"><?php esc_html_e( 'Cache', 'visigate' ); ?></th>
						<th style="width:180px"><?php esc_html_e( 'Honors Visigate’s signal', 'visigate' ); ?></th>
						<th><?php esc_html_e( 'Notes', 'visigate' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $caches as $c ) : ?>
						<tr>
							<td><?php echo esc_html( $c['name'] ); ?></td>
							<td><?php echo esc_html( $honors[ $c['honors'] ] ?? $c['honors'] ); ?></td>
							<td><?php echo esc_html( $c['note'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Rules a cached page can get wrong', 'visigate' ); ?></h2>
			<?php if ( ! $sets && ! $content ) : ?>
				<p><?php esc_html_e( 'None found. No published rule set or page uses visitor-specific conditions.', 'visigate' ); ?></p>
			<?php else : ?>
				<p style="max-width:780px"><?php esc_html_e( 'These use conditions that differ between visitors of the same URL. With automatic exclusion on, the pages that run them are not cached by plugins that honor the signal. Check them with the header test if your host or CDN caches pages.', 'visigate' ); ?></p>
				<table class="widefat striped" style="max-width:900px">
					<thead><tr>
						<th style="width:40%"><?php esc_html_e( 'Rule set or page', 'visigate' ); ?></th>
						<th><?php esc_html_e( 'Visitor-specific conditions', 'visigate' ); ?></th>
						<th style="width:120px"></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $sets as $row ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( (string) get_edit_post_link( $row['post']->ID ) ); ?>"><?php echo esc_html( $row['post']->post_title ? $row['post']->post_title : '#' . $row['post']->ID ); ?></a> <span style="color:#646970">(<?php esc_html_e( 'rule set', 'visigate' ); ?>)</span></td>
							<td><?php echo esc_html( implode( ', ', $row['types'] ) ); ?></td>
							<td><a href="<?php echo esc_url( Visigate_Usage::usage_url( $row['post']->ID ) ); ?>"><?php esc_html_e( 'Where used', 'visigate' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
					<?php foreach ( $content as $row ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( (string) get_edit_post_link( $row['post']->ID ) ); ?>"><?php echo esc_html( $row['post']->post_title ? $row['post']->post_title : __( '(no title)', 'visigate' ) ); ?></a></td>
							<td><?php echo esc_html( implode( ', ', $row['types'] ) ); ?></td>
							<td>
								<?php if ( is_post_type_viewable( $row['post']->post_type ) ) : ?>
									<a href="<?php echo esc_url( (string) get_permalink( $row['post'] ) ); ?>"><?php esc_html_e( 'View', 'visigate' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Header test', 'visigate' ); ?></h2>
			<p style="max-width:780px"><?php esc_html_e( 'Loads a page twice as a logged-out visitor and shows the cache headers. A "HIT" on the second load means a cache served a stored copy. If that page uses visitor-specific rules, exclude it from that cache.', 'visigate' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'visigate_cache_test' ); ?>
				<input type="hidden" name="action" value="visigate_cache_test">
				<input type="url" class="regular-text" name="url" value="<?php echo esc_attr( is_array( $test ) && ! empty( $test['url'] ) ? $test['url'] : home_url( '/' ) ); ?>">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Run test', 'visigate' ); ?></button>
			</form>
			<?php
			if ( is_array( $test ) ) {
				self::render_test( $test );
			}
			?>
		</div>
		<?php
	}

	private static function render_test( $test ) {
		if ( ! empty( $test['error'] ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $test['error'] ) . '</p></div>';
			return;
		}
		$runs = (array) ( $test['runs'] ?? array() );
		echo '<h3>' . esc_html( (string) $test['url'] ) . '</h3>';
		$cached = null;
		foreach ( $runs as $i => $run ) {
			/* translators: %d: 1 or 2. */
			echo '<h4>' . esc_html( sprintf( __( 'Load %d', 'visigate' ), $i + 1 ) ) . '</h4>';
			if ( ! empty( $run['error'] ) ) {
				echo '<p style="color:#b32d2e">' . esc_html( $run['error'] ) . '</p>';
				continue;
			}
			echo '<table class="widefat striped" style="max-width:900px"><tbody>';
			echo '<tr><td style="width:280px">' . esc_html__( 'Status', 'visigate' ) . '</td><td>' . (int) $run['code'] . '</td></tr>';
			foreach ( (array) $run['headers'] as $h => $v ) {
				echo '<tr><td><code>' . esc_html( $h ) . '</code></td><td><code>' . esc_html( $v ) . '</code></td></tr>';
			}
			echo '</tbody></table>';
			if ( 1 === $i ) {
				$cached = self::looks_cached( $run['headers'] );
			}
		}
		if ( true === $cached ) {
			echo '<div class="notice notice-warning inline" style="max-width:860px"><p>' . esc_html__( 'The second load came from a cache. If this page uses visitor-specific rules, every logged-out visitor sees the same copy: exclude the page from that cache (or turn on automatic exclusion and clear the cache).', 'visigate' ) . '</p></div>';
		} elseif ( false === $cached ) {
			echo '<div class="notice notice-success inline" style="max-width:860px"><p>' . esc_html__( 'The second load was not served from a cache, so rules on this page are evaluated for every visitor.', 'visigate' ) . '</p></div>';
		} else {
			echo '<div class="notice notice-info inline" style="max-width:860px"><p>' . esc_html__( 'The headers do not say whether a cache was used. Some hosts hide them; check your host\'s cache settings.', 'visigate' ) . '</p></div>';
		}
	}
}
