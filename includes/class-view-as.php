<?php
defined( 'ABSPATH' ) || exit;

/**
 * "View as": lets a Visigate manager preview the site as a logged-out visitor, as another role,
 * or on another device, without logging out.
 *
 * Only Visigate's own rule checks are simulated. The manager stays logged in with their real
 * account and permissions; nothing else on the site changes. The choice is kept in a session
 * cookie that is ignored for anyone who cannot manage Visigate.
 *
 * Add-ons can simulate more (Visigate Pro: country, cookies, referrer, WooCommerce states, date and
 * time) with the `visigate_view_as_fields` action, the `visigate_view_as_sanitize` and
 * `visigate_view_as_labels` filters, and `visigate_view_as_allow_time`.
 */
class Visigate_View_As {

	const COOKIE = 'visigate_view_as';
	const NONCE  = 'visigate_view_as';
	const PARAM  = 'visigate_view_as';

	private static $state = null;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'handle_quick_switch' ), 20 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 91 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_visigate_view_as', array( __CLASS__, 'handle_form' ) );
		add_action( 'template_redirect', array( __CLASS__, 'no_cache' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'bar_style' ) );
	}

	public static function enabled() {
		return (bool) Visigate_Admin::opt( 'view_as' );
	}

	private static function defaults() {
		return array( 'user' => '', 'device' => '', 'time' => '', 'extra' => array() );
	}

	/* ------------------------------- state ------------------------------- */

	/** Clean a raw state array (from the cookie or the form). */
	public static function sanitize( $raw ) {
		$raw   = is_array( $raw ) ? $raw : array();
		$state = self::defaults();

		$user = isset( $raw['user'] ) ? sanitize_text_field( (string) $raw['user'] ) : '';
		if ( 'guest' === $user ) {
			$state['user'] = 'guest';
		} elseif ( 0 === strpos( $user, 'role:' ) && wp_roles()->is_role( substr( $user, 5 ) ) ) {
			$state['user'] = $user;
		}

		$device = isset( $raw['device'] ) ? sanitize_key( (string) $raw['device'] ) : '';
		if ( in_array( $device, array( 'desktop', 'tablet', 'mobile' ), true ) ) {
			$state['device'] = $device;
		}

		$time = isset( $raw['time'] ) ? sanitize_text_field( (string) $raw['time'] ) : '';
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $time ) ) {
			$state['time'] = $time;
		}

		$extra          = apply_filters( 'visigate_view_as_sanitize', array(), isset( $raw['extra'] ) && is_array( $raw['extra'] ) ? $raw['extra'] : array() );
		$state['extra'] = is_array( $extra ) ? $extra : array();
		return $state;
	}

	/** Can the current request use View as? Front end only, for Visigate managers. */
	private static function applies() {
		if ( ! self::enabled() || ( is_admin() && ! wp_doing_ajax() ) || wp_doing_cron() ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST && isset( $_GET['context'] ) && 'edit' === $_GET['context'] ) {
			return false;
		}
		if ( ! did_action( 'plugins_loaded' ) || ! is_user_logged_in() ) {
			return false;
		}
		return current_user_can( visigate_capability() );
	}

	/** The active simulation (defaults when nothing is simulated). */
	public static function state() {
		if ( null !== self::$state ) {
			return self::$state;
		}
		$state = self::defaults();
		if ( self::applies() && isset( $_COOKIE[ self::COOKIE ] ) && is_string( $_COOKIE[ self::COOKIE ] ) ) {
			$json  = base64_decode( sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$state = self::sanitize( $json ? json_decode( $json, true ) : array() );
		}
		// Only cache once the user is known, so early calls do not lock in "no simulation".
		if ( did_action( 'init' ) ) {
			self::$state = $state;
		}
		return $state;
	}

	/** Raw saved state for the settings form (also outside the front end). */
	private static function saved_state() {
		if ( ! isset( $_COOKIE[ self::COOKIE ] ) || ! is_string( $_COOKIE[ self::COOKIE ] ) ) {
			return self::defaults();
		}
		$json = base64_decode( sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		return self::sanitize( $json ? json_decode( $json, true ) : array() );
	}

	public static function is_active() {
		$s = self::state();
		return '' !== $s['user'] || '' !== $s['device'] || null !== self::now() || ! empty( $s['extra'] );
	}

	/** An add-on value from the simulation, e.g. extra( 'country' ). */
	public static function extra( $key, $default = null ) {
		$s = self::state();
		return array_key_exists( $key, $s['extra'] ) ? $s['extra'][ $key ] : $default;
	}

	/** Is another visitor (logged-out or a role) being simulated instead of the real account? */
	public static function simulating_user() {
		return '' !== self::state()['user'];
	}

	private static function role() {
		$u = self::state()['user'];
		return 0 === strpos( $u, 'role:' ) ? substr( $u, 5 ) : '';
	}

	public static function logged_in() {
		$u = self::state()['user'];
		if ( 'guest' === $u ) {
			return false;
		}
		return '' !== $u ? true : is_user_logged_in();
	}

	public static function roles() {
		$u = self::state()['user'];
		if ( 'guest' === $u ) {
			return array();
		}
		$role = self::role();
		return '' !== $role ? array( $role ) : (array) wp_get_current_user()->roles;
	}

	/** Real user ID, or 0 while another visitor is simulated (no specific account matches). */
	public static function user_id() {
		return self::simulating_user() ? 0 : get_current_user_id();
	}

	public static function can( $cap ) {
		$u = self::state()['user'];
		if ( 'guest' === $u ) {
			return false;
		}
		$role = self::role();
		if ( '' !== $role ) {
			$obj = get_role( $role );
			return $obj && $obj->has_cap( $cap );
		}
		return current_user_can( $cap );
	}

	/** Simulated device, or null. */
	public static function device() {
		$d = self::state()['device'];
		return '' !== $d ? $d : null;
	}

	/** Simulated date and time (site time zone), or null. Needs an add-on to allow it. */
	public static function now() {
		$t = self::state()['time'];
		if ( '' === $t || ! apply_filters( 'visigate_view_as_allow_time', false ) ) {
			return null;
		}
		$d = date_create_immutable( $t, wp_timezone() );
		return $d ? $d : null;
	}

	/** Parts of the simulation, as short labels for the admin bar. */
	public static function labels( $state = null ) {
		$s     = null === $state ? self::state() : $state;
		$parts = array();
		if ( 'guest' === $s['user'] ) {
			$parts[] = __( 'Logged-out visitor', 'visigate' );
		} elseif ( 0 === strpos( $s['user'], 'role:' ) ) {
			$names   = wp_roles()->get_names();
			$slug    = substr( $s['user'], 5 );
			$parts[] = isset( $names[ $slug ] ) ? translate_user_role( $names[ $slug ] ) : $slug;
		}
		$devices = array( 'desktop' => __( 'Desktop', 'visigate' ), 'tablet' => __( 'Tablet', 'visigate' ), 'mobile' => __( 'Mobile', 'visigate' ) );
		if ( '' !== $s['device'] ) {
			$parts[] = $devices[ $s['device'] ];
		}
		if ( '' !== $s['time'] && apply_filters( 'visigate_view_as_allow_time', false ) ) {
			$d = date_create_immutable( $s['time'], wp_timezone() );
			if ( $d ) {
				$parts[] = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $d->getTimestamp() );
			}
		}
		return (array) apply_filters( 'visigate_view_as_labels', $parts, $s );
	}

	private static function write( $state ) {
		$state = self::sanitize( $state );
		$empty = '' === $state['user'] && '' === $state['device'] && '' === $state['time'] && empty( $state['extra'] );
		$path  = COOKIEPATH ? COOKIEPATH : '/';
		if ( $empty ) {
			setcookie( self::COOKIE, '', time() - YEAR_IN_SECONDS, $path, COOKIE_DOMAIN, is_ssl(), true );
			unset( $_COOKIE[ self::COOKIE ] );
		} else {
			$value = base64_encode( wp_json_encode( $state ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			setcookie( self::COOKIE, $value, 0, $path, COOKIE_DOMAIN, is_ssl(), true );
			$_COOKIE[ self::COOKIE ] = $value;
		}
		self::$state = null;
	}

	/* ------------------------------ requests ------------------------------ */

	private static function current_url() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$url  = $host ? set_url_scheme( 'http://' . $host . $uri ) : home_url( '/' );
		return remove_query_arg( array( self::PARAM, '_vgnonce' ), $url );
	}

	private static function quick_url( $value ) {
		return add_query_arg(
			array(
				self::PARAM => rawurlencode( $value ),
				'_vgnonce'  => wp_create_nonce( self::NONCE ),
			),
			self::current_url()
		);
	}

	/** Admin-bar links: ?visigate_view_as=guest | role:editor | user: | device:mobile | device: | off */
	public static function handle_quick_switch() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ self::PARAM ], $_GET['_vgnonce'] ) || ! self::enabled() ) {
			return;
		}
		$value = sanitize_text_field( wp_unslash( (string) $_GET[ self::PARAM ] ) );
		$nonce = sanitize_text_field( wp_unslash( (string) $_GET['_vgnonce'] ) );
		// phpcs:enable
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) || ! current_user_can( visigate_capability() ) ) {
			return;
		}
		$state = self::saved_state();
		if ( 'off' === $value ) {
			$state = self::defaults();
		} elseif ( 'guest' === $value || 0 === strpos( $value, 'role:' ) ) {
			$state['user'] = $value;
		} elseif ( 'user:' === $value ) {
			$state['user'] = '';
		} elseif ( 0 === strpos( $value, 'device:' ) ) {
			$state['device'] = substr( $value, 7 );
		}
		self::write( $state );
		wp_safe_redirect( self::current_url() );
		exit;
	}

	public static function handle_form() {
		check_admin_referer( self::NONCE );
		if ( ! current_user_can( visigate_capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'visigate' ), '', array( 'response' => 403 ) );
		}
		$raw    = isset( $_POST['view_as'] ) && is_array( $_POST['view_as'] ) ? wp_unslash( $_POST['view_as'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in sanitize().
		$return = isset( $_POST['return'] ) ? esc_url_raw( wp_unslash( $_POST['return'] ) ) : '';
		$return = wp_validate_redirect( $return, home_url( '/' ) );
		if ( isset( $_POST['stop'] ) ) {
			self::write( array() );
			wp_safe_redirect( add_query_arg( 'stopped', 1, self::page_url( $return ) ) );
			exit;
		}
		self::write( $raw );
		// "Inspect here": stay on the View As screen, which reads the page's rule checks in the background.
		if ( isset( $_POST['vg_inspect'] ) ) {
			wp_safe_redirect( add_query_arg( 'inspect', 1, self::page_url( $return ) ) );
			exit;
		}
		wp_safe_redirect( $return );
		exit;
	}

	/** Viewing as someone else: keep the response out of page caches. */
	public static function no_cache() {
		if ( ! self::is_active() ) {
			return;
		}
		nocache_headers();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard constant read by page-cache plugins.
		}
	}

	/* ------------------------------ admin bar ------------------------------ */

	public static function bar_style() {
		if ( is_admin_bar_showing() && self::is_active() ) {
			wp_add_inline_style( 'admin-bar', '#wpadminbar #wp-admin-bar-visigate-view-as.visigate-view-as-on > .ab-item{background:#996800;color:#fff}' );
		}
	}

	public static function page_url( $return = '' ) {
		$args = array( 'post_type' => Visigate_Ruleset::CPT, 'page' => 'visigate-view-as' );
		if ( $return ) {
			$args['return'] = rawurlencode( $return );
		}
		return add_query_arg( $args, admin_url( 'edit.php' ) );
	}

	public static function admin_bar( $bar ) {
		if ( is_admin() || ! self::enabled() || ! current_user_can( visigate_capability() ) ) {
			return;
		}
		$s      = self::state();
		$active = self::is_active();
		$labels = self::labels();
		$check  = function ( $on ) {
			return $on ? '✓ ' : '';
		};

		$bar->add_node(
			array(
				'id'    => 'visigate-view-as',
				'title' => $active
					/* translators: %s: what is being simulated, e.g. "Logged-out visitor · Mobile". */
					? sprintf( __( 'Viewing as: %s', 'visigate' ), $labels ? implode( ' · ', $labels ) : __( 'custom', 'visigate' ) )
					: __( 'View as', 'visigate' ),
				'href'  => self::page_url( self::current_url() ),
				'meta'  => array( 'class' => $active ? 'visigate-view-as-on' : '' ),
			)
		);

		// Visitor.
		$bar->add_node( array( 'parent' => 'visigate-view-as', 'id' => 'visigate-va-visitor', 'title' => __( 'Visitor', 'visigate' ) ) );
		$bar->add_node(
			array(
				'parent' => 'visigate-va-visitor',
				'id'     => 'visigate-va-user-real',
				'title'  => $check( '' === $s['user'] ) . __( 'Me (my real account)', 'visigate' ),
				'href'   => self::quick_url( 'user:' ),
			)
		);
		$bar->add_node(
			array(
				'parent' => 'visigate-va-visitor',
				'id'     => 'visigate-va-user-guest',
				'title'  => $check( 'guest' === $s['user'] ) . __( 'Logged-out visitor', 'visigate' ),
				'href'   => self::quick_url( 'guest' ),
			)
		);
		foreach ( wp_roles()->get_names() as $slug => $name ) {
			$bar->add_node(
				array(
					'parent' => 'visigate-va-visitor',
					'id'     => 'visigate-va-role-' . sanitize_html_class( $slug ),
					'title'  => $check( 'role:' . $slug === $s['user'] ) . esc_html( translate_user_role( $name ) ),
					'href'   => self::quick_url( 'role:' . $slug ),
				)
			);
		}

		// Device.
		$bar->add_node( array( 'parent' => 'visigate-view-as', 'id' => 'visigate-va-device', 'title' => __( 'Device', 'visigate' ) ) );
		$devices = array(
			''        => __( 'My real device', 'visigate' ),
			'desktop' => __( 'Desktop', 'visigate' ),
			'tablet'  => __( 'Tablet', 'visigate' ),
			'mobile'  => __( 'Mobile', 'visigate' ),
		);
		foreach ( $devices as $key => $label ) {
			$bar->add_node(
				array(
					'parent' => 'visigate-va-device',
					'id'     => 'visigate-va-device-' . ( $key ? $key : 'real' ),
					'title'  => $check( $key === $s['device'] ) . $label,
					'href'   => self::quick_url( 'device:' . $key ),
				)
			);
		}

		$bar->add_node(
			array(
				'parent' => 'visigate-view-as',
				'id'     => 'visigate-va-more',
				'title'  => __( 'More options…', 'visigate' ),
				'href'   => self::page_url( self::current_url() ),
			)
		);
		if ( $active ) {
			$bar->add_node(
				array(
					'parent' => 'visigate-view-as',
					'id'     => 'visigate-va-off',
					'title'  => self::stop_label( $labels ),
					'href'   => self::quick_url( 'off' ),
				)
			);
		}
	}

	/** "Stop viewing as Logged-out visitor · Mobile", or a plain "Stop previewing" when there is nothing to name. */
	private static function stop_label( $labels ) {
		return $labels
			/* translators: %s: what is being simulated, e.g. "Logged-out visitor · Mobile". */
			? sprintf( __( 'Stop viewing as %s', 'visigate' ), implode( ' · ', $labels ) )
			: __( 'Stop previewing', 'visigate' );
	}

	/* -------------------------------- page -------------------------------- */

	public static function menu() {
		if ( ! self::enabled() ) {
			return;
		}
		add_submenu_page(
			'edit.php?post_type=' . Visigate_Ruleset::CPT,
			__( 'View As', 'visigate' ),
			__( 'View As', 'visigate' ),
			visigate_capability(),
			'visigate-view-as',
			array( __CLASS__, 'render_page' )
		);
	}

	/** Pages offered in the Page list: home page, then published pages. */
	private static function page_choices( $current ) {
		$choices = array( home_url( '/' ) => __( 'Home page', 'visigate' ) );
		$pages   = get_pages( array( 'sort_column' => 'menu_order,post_title', 'number' => 150 ) );
		foreach ( (array) $pages as $p ) {
			$url = (string) get_permalink( $p );
			if ( $url && ! isset( $choices[ $url ] ) ) {
				$choices[ $url ] = '' !== $p->post_title ? $p->post_title : __( '(no title)', 'visigate' );
			}
		}
		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'shop', 'cart', 'myaccount' ) as $wc ) {
				$id = (int) wc_get_page_id( $wc );
				if ( $id > 0 && get_permalink( $id ) ) {
					$choices[ (string) get_permalink( $id ) ] = get_the_title( $id );
				}
			}
		}
		if ( $current && ! isset( $choices[ $current ] ) ) {
			$choices = array( $current => $current ) + $choices;
		}
		return $choices;
	}

	public static function render_page() {
		$s = self::saved_state();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$return  = isset( $_GET['return'] ) ? esc_url_raw( wp_unslash( (string) $_GET['return'] ) ) : '';
		$return  = wp_validate_redirect( $return, home_url( '/' ) );
		$stopped = isset( $_GET['stopped'] );
		$inspect = isset( $_GET['inspect'] );
		// phpcs:enable
		$active   = '' !== $s['user'] || '' !== $s['device'] || '' !== $s['time'] || ! empty( $s['extra'] );
		$labels   = self::labels( $s );
		$choices  = self::page_choices( $return );
		$page_txt = $choices[ $return ] ?? $return;
		$inspect  = $inspect || $active;
		$who      = $active ? $labels : array( __( 'You (nothing simulated)', 'visigate' ) );
		?>
		<div class="wrap vg-wrap vg-page">
			<h1 class="vg-sr"><?php esc_html_e( 'View As', 'visigate' ); ?></h1>
			<hr class="wp-header-end">
			<?php if ( $stopped ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Stopped. You now see the site as yourself again.', 'visigate' ); ?></p></div>
			<?php endif; ?>

			<div class="vg-cols vg-va-cols">
				<form class="vg-card vg-va-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" aria-labelledby="vg-va-title">
					<?php wp_nonce_field( self::NONCE ); ?>
					<input type="hidden" name="action" value="visigate_view_as">
					<h2 id="vg-va-title"><?php esc_html_e( 'Preview a page as someone else', 'visigate' ); ?></h2>

					<fieldset class="vg-va-field">
						<legend><?php esc_html_e( 'Visitor', 'visigate' ); ?></legend>
						<div class="vg-pills">
							<?php
							$visitors = array(
								'guest' => __( 'Logged-out visitor', 'visigate' ),
								''      => __( 'Me (my real account)', 'visigate' ),
							);
							foreach ( wp_roles()->get_names() as $slug => $role_name ) {
								$visitors[ 'role:' . $slug ] = translate_user_role( $role_name );
							}
							foreach ( $visitors as $value => $label ) :
								?>
								<label class="vg-pill-option">
									<input type="radio" name="view_as[user]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $s['user'], $value ); ?>>
									<span><?php echo esc_html( $label ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
						<p class="vg-va-note"><?php esc_html_e( 'A role preview matches role and capability rules for that role. Specific-user rules do not match, and account age uses your own account.', 'visigate' ); ?></p>
					</fieldset>

					<fieldset class="vg-va-field">
						<legend><?php esc_html_e( 'Device', 'visigate' ); ?></legend>
						<div class="vg-segment">
							<?php
							$devices = array(
								''        => __( 'My real device', 'visigate' ),
								'desktop' => __( 'Desktop', 'visigate' ),
								'tablet'  => __( 'Tablet', 'visigate' ),
								'mobile'  => __( 'Mobile', 'visigate' ),
							);
							foreach ( $devices as $value => $label ) :
								?>
								<label class="vg-seg-option">
									<input type="radio" name="view_as[device]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $s['device'], $value ); ?>>
									<span><?php echo esc_html( $label ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
					</fieldset>

					<?php if ( has_action( 'visigate_view_as_fields' ) ) : ?>
						<div class="vg-va-pro">
							<table class="form-table" role="presentation">
								<?php do_action( 'visigate_view_as_fields', $s ); ?>
							</table>
						</div>
					<?php else : ?>
						<div class="vg-va-pro is-upsell">
							<h3><?php esc_html_e( 'Simulate Pro conditions', 'visigate' ); ?> <span class="vg-pill"><?php esc_html_e( 'Pro', 'visigate' ); ?></span></h3>
							<p><?php esc_html_e( 'Visigate Pro can also simulate country, cookies, referrer and WooCommerce states, and its Business plan can preview a chosen date and time.', 'visigate' ); ?></p>
							<a href="<?php echo esc_url( (string) apply_filters( 'visigate_upgrade_url', 'https://persjay.com/plugins/visigate/#pricing' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Learn more', 'visigate' ); ?></a>
						</div>
					<?php endif; ?>

					<div class="vg-va-field">
						<label class="vg-va-label" for="vg-va-return"><?php esc_html_e( 'Page', 'visigate' ); ?></label>
						<select id="vg-va-return" name="return">
							<?php foreach ( $choices as $url => $title ) : ?>
								<option value="<?php echo esc_url( $url ); ?>" <?php selected( $url, $return ); ?>><?php echo esc_html( $title ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="vg-va-submit">
						<button type="submit" formtarget="_blank" class="vg-btn is-primary is-lg vg-va-open" data-vg-reload="<?php echo esc_url( self::page_url() ); ?>"><?php esc_html_e( 'Open this page as this visitor', 'visigate' ); ?> <span class="vg-sr"><?php esc_html_e( '(opens in a new tab)', 'visigate' ); ?></span><span aria-hidden="true">↗</span></button>
						<button type="submit" name="vg_inspect" value="1" class="vg-btn is-lg"><?php esc_html_e( 'Inspect here', 'visigate' ); ?></button>
						<?php if ( $active ) : ?>
							<button type="submit" name="stop" value="1" class="vg-btn"><?php echo esc_html( self::stop_label( $labels ) ); ?></button>
						<?php endif; ?>
					</div>
					<p class="vg-va-note"><?php esc_html_e( 'The page opens in a new tab and this screen stays open. You stay logged in, and a bar at the top of the page shows who you are viewing as, with Stop previewing.', 'visigate' ); ?></p>
				</form>

				<section class="vg-inspector" aria-labelledby="vg-insp-title" data-url="<?php echo esc_url( $inspect ? add_query_arg( 'visigate_inspect_data', '1', $return ) : '' ); ?>">
					<div class="vg-inspector-head">
						<h2 id="vg-insp-title"><span class="vg-inspector-kicker"><?php esc_html_e( 'Rule inspector', 'visigate' ); ?></span> <span class="vg-inspector-what"><?php echo esc_html( $inspect ? implode( ' · ', array_merge( array( $page_txt ), $who ) ) : $page_txt ); ?></span></h2>
						<span class="vg-inspector-counts" aria-live="polite"></span>
					</div>
					<div class="vg-inspector-body">
						<?php if ( $inspect ) : ?>
							<p class="vg-inspector-empty"><?php esc_html_e( 'Checking the page…', 'visigate' ); ?></p>
						<?php else : ?>
							<div class="vg-inspector-empty">
								<p><strong><?php esc_html_e( 'See why each piece of content is shown or hidden.', 'visigate' ); ?></strong></p>
								<p><?php esc_html_e( 'Choose a visitor and a page, then press Inspect here. Every block, Elementor element and menu item with a rule is listed with the conditions that passed or failed.', 'visigate' ); ?></p>
							</div>
						<?php endif; ?>
					</div>
				</section>
			</div>
			<?php Visigate_Admin_UI::footer(); ?>
		</div>
		<?php
	}
}
