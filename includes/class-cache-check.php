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
		add_action( 'save_post_' . Visigate_Ruleset::CPT, array( __CLASS__, 'flush_issues' ) );
		add_action( 'update_option_' . Visigate_Admin::OPTION, array( __CLASS__, 'flush_issues' ) );
		add_action( 'add_option_' . Visigate_Admin::OPTION, array( __CLASS__, 'flush_issues' ) );
		add_action( 'activated_plugin', array( __CLASS__, 'flush_issues' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'flush_issues' ) );
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

	/* -------------------------------- issues -------------------------------- */

	/**
	 * Problems worth a warning: visitor-specific rules exist AND something could still cache them
	 * (automatic exclusion off, or a cache that only reads headers). Cached for 10 minutes.
	 *
	 * @return string[]
	 */
	public static function issues( $fresh = false ) {
		$cached = $fresh ? false : get_transient( 'visigate_cache_issues' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$out  = array();
		$risk = self::affected_sets() || self::affected_content( 1 );
		if ( $risk ) {
			if ( ! Visigate_Admin::opt( 'auto_nocache' ) ) {
				$out[] = __( 'Automatic cache exclusion is off, so page caches may store pages whose rules change from visitor to visitor.', 'visigate' );
			}
			foreach ( self::detect() as $c ) {
				if ( 'yes' !== $c['honors'] ) {
					/* translators: %s: cache name, such as Cloudflare. */
					$out[] = sprintf( __( '%s only reads response headers, so it can still store pages that use visitor-specific rules.', 'visigate' ), $c['name'] );
				}
			}
		}
		set_transient( 'visigate_cache_issues', $out, 10 * MINUTE_IN_SECONDS );
		return $out;
	}

	public static function issue_count() {
		return count( self::issues() );
	}

	public static function issue_summary() {
		$issues = self::issues();
		return $issues ? $issues[0] : '';
	}

	public static function flush_issues() {
		delete_transient( 'visigate_cache_issues' );
	}

	/* --------------------------------- page --------------------------------- */

	public static function render_page() {
		if ( ! current_user_can( visigate_capability() ) ) {
			return;
		}
		$issues   = self::issues( true );
		$caches   = self::detect();
		$auto     = (bool) Visigate_Admin::opt( 'auto_nocache' );
		$sets     = self::affected_sets();
		$content  = self::affected_content();
		$test     = get_transient( self::transient_key() );
		$wp_cache = defined( 'WP_CACHE' ) && WP_CACHE;
		$dropin   = file_exists( WP_CONTENT_DIR . '/advanced-cache.php' );
		$block    = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
		$settings = admin_url( 'edit.php?post_type=' . Visigate_Ruleset::CPT . '&page=visigate-settings' );
		$honors   = array(
			'yes'     => array( __( 'Honors the signal', 'visigate' ), 'is-ok', 'is-ok' ),
			'headers' => array( __( 'Headers only', 'visigate' ), 'is-warn', 'is-warn' ),
			'unknown' => array( __( 'Unknown', 'visigate' ), '', 'is-off' ),
		);
		?>
		<div class="wrap vg-wrap vg-page">
			<h1 class="vg-sr"><?php esc_html_e( 'Cache Check', 'visigate' ); ?></h1>
			<hr class="wp-header-end">

			<?php if ( $issues ) : ?>
				<section class="vg-banner is-warn">
					<span class="vg-banner-icon" aria-hidden="true">!</span>
					<div class="vg-banner-main">
						<h2><?php /* translators: %d: number of problems */ echo esc_html( sprintf( _n( '%d thing can make a cache show the wrong version of a page', '%d things can make a cache show the wrong version of a page', count( $issues ), 'visigate' ), count( $issues ) ) ); ?></h2>
						<?php foreach ( $issues as $issue ) : ?>
							<p><?php echo esc_html( $issue ); ?></p>
						<?php endforeach; ?>
					</div>
					<a class="vg-btn is-dark" href="#vg-cache-test"><?php esc_html_e( 'Run the header test', 'visigate' ); ?></a>
				</section>
			<?php else : ?>
				<section class="vg-banner is-ok">
					<span class="vg-banner-icon" aria-hidden="true">✓</span>
					<div class="vg-banner-main">
						<h2><?php esc_html_e( 'No cache problems found', 'visigate' ); ?></h2>
						<p>
							<?php
							echo esc_html(
								( $sets || $content )
									? __( 'Pages with visitor-specific rules tell your caches not to store them, and every cache found here listens.', 'visigate' )
									: __( 'No published rule set or page uses visitor-specific conditions, so a cached page shows the same content anyone would see.', 'visigate' )
							);
							?>
						</p>
					</div>
					<a class="vg-btn" href="#vg-cache-test"><?php esc_html_e( 'Run the header test', 'visigate' ); ?></a>
				</section>
			<?php endif; ?>

			<div class="vg-cols">
				<div class="vg-col-main">
					<section class="vg-card" aria-labelledby="vg-cc-found">
						<div class="vg-card-head"><h2 id="vg-cc-found"><?php esc_html_e( 'Caching on this site', 'visigate' ); ?></h2></div>
						<div class="vg-item">
							<span class="vg-dot <?php echo $auto ? 'is-ok' : 'is-bad'; ?>" aria-hidden="true"></span>
							<div class="vg-item-main">
								<strong><?php esc_html_e( 'Automatic cache exclusion', 'visigate' ); ?></strong>
								<span><?php echo esc_html( $auto ? __( 'On. Pages that run visitor-specific rules tell caches not to store them.', 'visigate' ) : __( 'Off. Turn it on in Settings unless you manage cache exclusions yourself.', 'visigate' ) ); ?></span>
							</div>
							<a href="<?php echo esc_url( $settings ); ?>"><?php esc_html_e( 'Settings', 'visigate' ); ?></a>
						</div>
						<div class="vg-item">
							<span class="vg-dot <?php echo ( $wp_cache || $dropin ) ? 'is-ok' : 'is-off'; ?>" aria-hidden="true"></span>
							<div class="vg-item-main">
								<strong><?php esc_html_e( 'Page caching in WordPress', 'visigate' ); ?></strong>
								<span><?php echo esc_html( ( $wp_cache || $dropin ) ? __( 'A page cache is enabled (WP_CACHE / advanced-cache.php).', 'visigate' ) : __( 'No WordPress page-cache drop-in found. Your host or a CDN may still cache pages; run the header test below to check.', 'visigate' ) ); ?></span>
							</div>
						</div>
						<div class="vg-item">
							<span class="vg-dot is-off" aria-hidden="true"></span>
							<div class="vg-item-main">
								<strong><?php echo esc_html( $block ? __( 'Block theme', 'visigate' ) : __( 'Classic theme', 'visigate' ) ); ?></strong>
								<span>
									<?php
									echo esc_html(
										$block
											? __( 'Rules run before any output, so Visigate can also send no-cache headers that server and CDN caches understand.', 'visigate' )
											: __( 'Rules usually run after headers are sent, so server and CDN caches (Varnish, Nginx, Cloudflare) do not receive a no-cache header. Plugin caches still honor the signal.', 'visigate' )
									);
									?>
								</span>
							</div>
						</div>
						<?php if ( ! $caches ) : ?>
							<div class="vg-item">
								<span class="vg-dot is-off" aria-hidden="true"></span>
								<div class="vg-item-main">
									<strong><?php esc_html_e( 'Cache plugins and hosts', 'visigate' ); ?></strong>
									<span><?php esc_html_e( 'No known cache plugin or managed host was detected.', 'visigate' ); ?></span>
								</div>
							</div>
						<?php endif; ?>
						<?php foreach ( $caches as $c ) : ?>
							<?php $h = $honors[ $c['honors'] ] ?? array( $c['honors'], '', 'is-off' ); ?>
							<div class="vg-item<?php echo 'headers' === $c['honors'] && ( $sets || $content ) ? ' is-warn' : ''; ?>">
								<span class="vg-dot <?php echo esc_attr( $h[2] ); ?>" aria-hidden="true"></span>
								<div class="vg-item-main">
									<strong><?php echo esc_html( $c['name'] ); ?></strong>
									<?php if ( '' !== (string) $c['note'] ) : ?>
										<span><?php echo esc_html( $c['note'] ); ?></span>
									<?php endif; ?>
								</div>
								<span class="vg-badge <?php echo esc_attr( $h[1] ); ?>"><?php echo esc_html( $h[0] ); ?></span>
							</div>
						<?php endforeach; ?>
					</section>

					<section class="vg-card" aria-labelledby="vg-cc-rules">
						<div class="vg-card-head"><h2 id="vg-cc-rules"><?php esc_html_e( 'Rules a cached page can get wrong', 'visigate' ); ?></h2></div>
						<?php if ( ! $sets && ! $content ) : ?>
							<div class="vg-empty"><p><?php esc_html_e( 'None found. No published rule set or page uses visitor-specific conditions.', 'visigate' ); ?></p></div>
						<?php else : ?>
							<div class="vg-card-body"><p class="vg-muted"><?php esc_html_e( 'These use conditions that differ between visitors of the same URL. With automatic exclusion on, the pages that run them are not cached by plugins that honor the signal. Check them with the header test if your host or CDN caches pages.', 'visigate' ); ?></p></div>
							<div class="vg-rows">
								<?php foreach ( $sets as $row ) : ?>
									<div class="vg-row vg-grid-cache">
										<div class="vg-row-name"><div>
											<a class="vg-row-title" href="<?php echo esc_url( (string) get_edit_post_link( $row['post']->ID ) ); ?>"><?php echo esc_html( $row['post']->post_title ? $row['post']->post_title : '#' . $row['post']->ID ); ?></a>
											<span class="vg-kind"><?php esc_html_e( 'Rule set', 'visigate' ); ?></span>
										</div></div>
										<div class="vg-chips"><?php foreach ( $row['types'] as $t ) : ?><span class="vg-chip is-warn"><?php echo esc_html( $t ); ?></span><?php endforeach; ?></div>
										<a href="<?php echo esc_url( Visigate_Usage::usage_url( $row['post']->ID ) ); ?>"><?php esc_html_e( 'Where used', 'visigate' ); ?></a>
									</div>
								<?php endforeach; ?>
								<?php foreach ( $content as $row ) : ?>
									<?php $pto = get_post_type_object( $row['post']->post_type ); ?>
									<div class="vg-row vg-grid-cache">
										<div class="vg-row-name"><div>
											<a class="vg-row-title" href="<?php echo esc_url( (string) get_edit_post_link( $row['post']->ID ) ); ?>"><?php echo esc_html( $row['post']->post_title ? $row['post']->post_title : __( '(no title)', 'visigate' ) ); ?></a>
											<span class="vg-kind"><?php echo esc_html( $pto ? $pto->labels->singular_name : $row['post']->post_type ); ?></span>
										</div></div>
										<div class="vg-chips"><?php foreach ( $row['types'] as $t ) : ?><span class="vg-chip is-warn"><?php echo esc_html( $t ); ?></span><?php endforeach; ?></div>
										<span>
											<?php if ( is_post_type_viewable( $row['post']->post_type ) ) : ?>
												<a href="<?php echo esc_url( (string) get_permalink( $row['post'] ) ); ?>"><?php esc_html_e( 'View', 'visigate' ); ?></a>
											<?php endif; ?>
										</span>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</section>

					<section class="vg-card" id="vg-cache-test" aria-labelledby="vg-cc-test">
						<div class="vg-card-head"><h2 id="vg-cc-test"><?php esc_html_e( 'Header test', 'visigate' ); ?></h2></div>
						<div class="vg-card-body">
							<p class="vg-muted"><?php esc_html_e( 'Loads a page twice as a logged-out visitor and shows the cache headers. A "HIT" on the second load means a cache served a stored copy. If that page uses visitor-specific rules, exclude it from that cache.', 'visigate' ); ?></p>
							<form class="vg-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( 'visigate_cache_test' ); ?>
								<input type="hidden" name="action" value="visigate_cache_test">
								<label class="vg-sr" for="vg-cache-url"><?php esc_html_e( 'Page address to test', 'visigate' ); ?></label>
								<input id="vg-cache-url" type="url" name="url" value="<?php echo esc_attr( is_array( $test ) && ! empty( $test['url'] ) ? $test['url'] : home_url( '/' ) ); ?>">
								<button type="submit" class="vg-btn is-primary"><?php esc_html_e( 'Run test', 'visigate' ); ?></button>
							</form>
							<?php
							if ( is_array( $test ) ) {
								self::render_test( $test );
							}
							?>
						</div>
					</section>
				</div>

				<aside class="vg-col-side">
					<?php if ( $issues ) : ?>
						<section class="vg-card is-pad" aria-labelledby="vg-cc-fix">
							<h2 id="vg-cc-fix"><?php esc_html_e( 'How to fix it', 'visigate' ); ?></h2>
							<ol class="vg-steps">
								<?php if ( ! $auto ) : ?>
									<li><?php printf( /* translators: %s: link to Settings */ esc_html__( 'Turn on Cache safety in %s.', 'visigate' ), '<a href="' . esc_url( $settings ) . '">' . esc_html__( 'Settings', 'visigate' ) . '</a>' ); ?></li>
								<?php endif; ?>
								<?php foreach ( $caches as $c ) : ?>
									<?php if ( 'yes' !== $c['honors'] ) : ?>
										<li><?php /* translators: %s: cache name */ echo esc_html( sprintf( __( 'In %s, exclude (bypass) the pages listed under “Rules a cached page can get wrong”.', 'visigate' ), $c['name'] ) ); ?></li>
									<?php endif; ?>
								<?php endforeach; ?>
								<li><?php esc_html_e( 'Clear the cache once so stored copies are removed.', 'visigate' ); ?></li>
								<li><?php esc_html_e( 'Run the header test on one of those pages to confirm.', 'visigate' ); ?></li>
							</ol>
						</section>
					<?php endif; ?>
					<section class="vg-card is-pad" aria-labelledby="vg-cc-how">
						<h2 id="vg-cc-how"><?php esc_html_e( 'Why caching matters', 'visigate' ); ?></h2>
						<p class="vg-muted"><?php esc_html_e( 'Visibility rules run on the server for each visit. A page cache stores one copy of a page and serves it to many visitors, so rules that depend on the visitor can show the wrong content from a cached copy. Login and role rules are usually safe because page caches skip logged-in visitors.', 'visigate' ); ?></p>
					</section>
					<section class="vg-card is-pad" aria-labelledby="vg-cc-types">
						<h2 id="vg-cc-types"><?php esc_html_e( 'Conditions that change per visitor', 'visigate' ); ?></h2>
						<p class="vg-muted"><?php esc_html_e( 'Pages using these are the ones a cache can get wrong.', 'visigate' ); ?></p>
						<div class="vg-chips">
							<?php foreach ( self::dynamic_types() as $label ) : ?>
								<span class="vg-chip is-warn"><?php echo esc_html( $label ); ?></span>
							<?php endforeach; ?>
						</div>
					</section>
				</aside>
			</div>
			<?php Visigate_Admin_UI::footer(); ?>
		</div>
		<?php
	}

	private static function render_test( $test ) {
		if ( ! empty( $test['error'] ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $test['error'] ) . '</p></div>';
			return;
		}
		$runs = (array) ( $test['runs'] ?? array() );
		echo '<h3 style="margin-top:8px">' . esc_html( (string) $test['url'] ) . '</h3>';
		$cached = null;
		foreach ( $runs as $i => $run ) {
			/* translators: %d: 1 or 2. */
			echo '<h4 style="margin:6px 0 0">' . esc_html( sprintf( __( 'Load %d', 'visigate' ), $i + 1 ) ) . '</h4>';
			if ( ! empty( $run['error'] ) ) {
				echo '<p style="color:#b91c1c">' . esc_html( $run['error'] ) . '</p>';
				continue;
			}
			echo '<table class="widefat striped"><tbody>';
			echo '<tr><td style="width:240px">' . esc_html__( 'Status', 'visigate' ) . '</td><td>' . (int) $run['code'] . '</td></tr>';
			foreach ( (array) $run['headers'] as $h => $v ) {
				echo '<tr><td><code>' . esc_html( $h ) . '</code></td><td><code>' . esc_html( $v ) . '</code></td></tr>';
			}
			echo '</tbody></table>';
			if ( 1 === $i ) {
				$cached = self::looks_cached( $run['headers'] );
			}
		}
		if ( true === $cached ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The second load came from a cache. If this page uses visitor-specific rules, every logged-out visitor sees the same copy: exclude the page from that cache (or turn on automatic exclusion and clear the cache).', 'visigate' ) . '</p></div>';
		} elseif ( false === $cached ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'The second load was not served from a cache, so rules on this page are evaluated for every visitor.', 'visigate' ) . '</p></div>';
		} else {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'The headers do not say whether a cache was used. Some hosts hide them; check your host\'s cache settings.', 'visigate' ) . '</p></div>';
		}
	}
}
