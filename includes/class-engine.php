<?php
defined( 'ABSPATH' ) || exit;

/**
 * Evaluates rules. Everything is server-side, memoised per request, and
 * WooCommerce lookups hit an in-memory per-user purchase index instead of
 * running order queries per condition.
 */
class Visigate_Engine {

	const PIDX_META = '_visigate_pidx';

	private static $memo = array();

	public static function init() {
		// Keep the purchase index fresh.
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'bust_index' ) );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'bust_index' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Public evaluation API
	 * ------------------------------------------------------------------ */

	/** Visibility of a saved rule set. Missing/unpublished sets fail closed (hidden). */
	public static function set_visible( $id ) {
		$id   = (int) $id;
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || Visigate_Ruleset::CPT !== $post->post_type || 'publish' !== $post->post_status ) {
			return (bool) apply_filters( 'visigate_missing_set_visible', false, $id );
		}
		return self::visible( get_post_meta( $id, Visigate_Ruleset::META, true ) );
	}

	/** Visibility for a rules array. Empty rules = always visible. */
	public static function visible( $rules ) {
		if ( ! is_array( $rules ) || empty( $rules['groups'] ) ) {
			return true;
		}
		$key = md5( wp_json_encode( $rules ) );
		if ( isset( self::$memo[ $key ] ) ) {
			return self::$memo[ $key ];
		}

		$all     = Visigate_Conditions::all();
		$action  = ( ( $rules['action'] ?? 'show' ) === 'hide' ) ? 'hide' : 'show';
		$any     = false;
		$matched = false;
		$dynamic = false;

		// Pass 1: work out which conditions matter, and whether the page becomes uncacheable.
		$groups = array();
		foreach ( (array) $rules['groups'] as $g ) {
			$conds = array_values( array_filter( (array) ( $g['conditions'] ?? array() ), array( 'Visigate_Conditions', 'is_complete' ) ) );
			if ( ! $conds ) {
				continue;
			}
			foreach ( $conds as $c ) {
				if ( ! empty( $all[ $c['type'] ]['dynamic'] ) ) {
					$dynamic = true;
				}
			}
			$groups[] = $conds;
		}
		if ( $dynamic ) {
			self::mark_dynamic();
		}
		if ( ! $groups ) {
			return self::$memo[ $key ] = true;
		}

		// Pass 2: OR of ANDs, short-circuiting.
		foreach ( $groups as $conds ) {
			$ok = true;
			foreach ( $conds as $c ) {
				if ( ! self::check( $c ) ) {
					$ok = false;
					break;
				}
			}
			if ( $ok ) {
				$matched = true;
				break;
			}
		}

		return self::$memo[ $key ] = ( 'show' === $action ) ? $matched : ! $matched;
	}

	/** Tell page caches not to store this response (visitor-specific rules in play). */
	public static function mark_dynamic() {
		if ( ! Visigate_Admin::opt( 'auto_nocache' ) || is_admin() ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // WP Rocket, W3TC, WP Super Cache, LiteSpeed, etc.
		}
		do_action( 'visigate_dynamic_page' ); // Hook for host-level cache purging / Vary headers.
	}

	/* ------------------------------------------------------------------ *
	 * Single condition check
	 * ------------------------------------------------------------------ */

	public static function check( $c ) {
		$type = $c['type'];
		$op   = $c['op'] ?? '';
		$v    = $c['value'] ?? null;

		switch ( $type ) {
			case 'logged_in':
				return ( 'is' === $op ) ? is_user_logged_in() : ! is_user_logged_in();

			case 'user_role':
				$hit = (bool) array_intersect( (array) wp_get_current_user()->roles, array_map( 'strval', (array) $v ) );
				return ( 'is_any' === $op ) ? $hit : ! $hit;

			case 'capability':
				$has = is_user_logged_in() && current_user_can( sanitize_key( (string) $v ) );
				return ( 'has' === $op ) ? $has : ! $has;

			case 'user_registered':
				if ( ! is_user_logged_in() ) {
					return false;
				}
				$age = ( time() - strtotime( wp_get_current_user()->user_registered . ' UTC' ) ) / DAY_IN_SECONDS;
				return ( 'older' === $op ) ? $age > (float) $v : $age < (float) $v;

			case 'user_meta':
				return self::user_meta_check( $c );

			case 'device':
				$hit = in_array( self::device(), (array) $v, true );
				return ( 'is_any' === $op ) ? $hit : ! $hit;

			case 'country':
				$hit = in_array( self::country(), (array) $v, true );
				return ( 'is_any' === $op ) ? $hit : ! $hit;

			case 'url_param':
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$actual = isset( $_GET[ $v['key'] ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $v['key'] ] ) ) : null;
				return self::string_check( $actual, $op, (string) $v['value'] );

			case 'cookie':
				$actual = isset( $_COOKIE[ $v['key'] ] ) ? sanitize_text_field( wp_unslash( (string) $_COOKIE[ $v['key'] ] ) ) : null;
				return self::string_check( $actual, $op, (string) $v['value'] );

			case 'referrer':
				$ref = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
				$hit = '' !== $ref && false !== stripos( $ref, (string) $v );
				return ( 'contains' === $op ) ? $hit : ! $hit;

			case 'weekday':
				$hit = in_array( current_datetime()->format( 'N' ), array_map( 'strval', (array) $v ), true );
				return ( 'is_any' === $op ) ? $hit : ! $hit;

			case 'time_of_day':
				$now = current_datetime();
				$cur = (int) $now->format( 'G' ) * 60 + (int) $now->format( 'i' );
				$f   = self::minutes( $v['from'] );
				$t   = self::minutes( $v['to'] );
				$in  = ( $f <= $t ) ? ( $cur >= $f && $cur < $t ) : ( $cur >= $f || $cur < $t ); // supports overnight ranges
				return ( 'between' === $op ) ? $in : ! $in;

			case 'date_range':
				$now = current_datetime()->getTimestamp();
				$f   = ! empty( $v['from'] ) ? date_create_immutable( $v['from'], wp_timezone() ) : null;
				$t   = ! empty( $v['to'] ) ? date_create_immutable( $v['to'], wp_timezone() ) : null;
				$in  = ( ! $f || $now >= $f->getTimestamp() ) && ( ! $t || $now <= $t->getTimestamp() );
				return ( 'within' === $op ) ? $in : ! $in;

			case 'woo_bought_product':
			case 'woo_bought_category':
				$has = self::bought( 'woo_bought_product' === $type ? 'p' : 'c', (array) $v, (int) ( $c['days'] ?? 0 ) );
				return ( 'bought' === $op ) ? $has : ! $has;

			case 'woo_order_count':
				$n = ( is_user_logged_in() && function_exists( 'wc_get_customer_order_count' ) ) ? wc_get_customer_order_count( get_current_user_id() ) : 0;
				return self::number_check( $n, $op, $v );

			case 'woo_total_spent':
				$n = ( is_user_logged_in() && function_exists( 'wc_get_customer_total_spent' ) ) ? (float) wc_get_customer_total_spent( get_current_user_id() ) : 0;
				return self::number_check( $n, $op, $v );

			case 'woo_cart_product':
				$has = (bool) array_intersect( self::cart_product_ids(), array_map( 'intval', (array) $v ) );
				return ( 'has' === $op ) ? $has : ! $has;

			case 'woo_cart_total':
				$cart = self::cart();
				return self::number_check( $cart ? (float) $cart->get_subtotal() : 0, $op, $v );

			case 'woo_subscription':
				$has = is_user_logged_in() && function_exists( 'wcs_user_has_subscription' ) && wcs_user_has_subscription( get_current_user_id(), '', 'active' );
				return ( 'active' === $op ) ? $has : ! $has;
		}

		// Custom conditions added through the `visigate_conditions` filter.
		return (bool) apply_filters( 'visigate_check_condition', false, $c );
	}

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	private static function minutes( $hhmm ) {
		$p = explode( ':', (string) $hhmm );
		return ( (int) $p[0] ) * 60 + (int) ( $p[1] ?? 0 );
	}

	private static function number_check( $actual, $op, $expected ) {
		$expected = (float) $expected;
		switch ( $op ) {
			case 'gte':
				return $actual >= $expected;
			case 'lte':
				return $actual <= $expected;
			default:
				return abs( $actual - $expected ) < 0.00001;
		}
	}

	private static function string_check( $actual, $op, $expected ) {
		switch ( $op ) {
			case 'exists':
				return null !== $actual && '' !== $actual;
			case 'not_exists':
				return null === $actual || '' === $actual;
			case 'equals':
				return null !== $actual && $actual === $expected;
			case 'not_equals':
				return $actual !== $expected;
			case 'contains':
				return null !== $actual && false !== stripos( $actual, $expected );
		}
		return false;
	}

	private static function user_meta_check( $c ) {
		$key = (string) $c['value']['key'];
		if ( ! is_user_logged_in() || in_array( $key, array( 'session_tokens', 'user_pass' ), true ) ) {
			return false;
		}
		$raw    = get_user_meta( get_current_user_id(), $key, true );
		$actual = is_scalar( $raw ) && '' !== (string) $raw ? (string) $raw : null;
		return self::string_check( $actual, $c['op'], (string) $c['value']['value'] );
	}

	public static function device() {
		static $d = null;
		if ( null !== $d ) {
			return $d;
		}
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore
		if ( preg_match( '/ipad|tablet|kindle|silk|playbook|(android(?!.*mobile))/i', $ua ) ) {
			$d = 'tablet';
		} elseif ( wp_is_mobile() ) {
			$d = 'mobile';
		} else {
			$d = 'desktop';
		}
		return $d = apply_filters( 'visigate_device', $d );
	}

	/**
	 * Visitor country (ISO alpha-2). No external API calls: reads CDN / server headers,
	 * then WooCommerce's local MaxMind database if configured. Filter to plug in anything else.
	 */
	public static function country() {
		static $cc = null;
		if ( null !== $cc ) {
			return $cc;
		}
		$found = apply_filters( 'visigate_country_code', null );
		if ( ! $found ) {
			foreach ( array( 'HTTP_CF_IPCOUNTRY', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY', 'HTTP_X_VERCEL_IP_COUNTRY', 'HTTP_X_APPENGINE_COUNTRY', 'HTTP_X_COUNTRY_CODE', 'GEOIP_COUNTRY_CODE', 'HTTP_X_GEO_COUNTRY' ) as $h ) {
				if ( ! empty( $_SERVER[ $h ] ) ) {
					$found = sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) );
					break;
				}
			}
		}
		if ( ! $found && class_exists( 'WC_Geolocation' ) ) {
			$g     = WC_Geolocation::geolocate_ip( '', false, false ); // local DB only, never a remote call
			$found = $g['country'] ?? '';
		}
		$found = strtoupper( (string) $found );
		return $cc = ( preg_match( '/^[A-Z]{2}$/', $found ) && ! in_array( $found, array( 'XX', 'T1' ), true ) ) ? $found : '';
	}

	/* ------------------------------------------------------------------ *
	 * WooCommerce
	 * ------------------------------------------------------------------ */

	private static function cart() {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		if ( ! WC()->cart && function_exists( 'wc_load_cart' ) && ! is_admin() ) {
			wc_load_cart();
		}
		return WC()->cart ?: null;
	}

	private static function cart_product_ids() {
		static $ids = null;
		$cart = self::cart();
		if ( ! $cart ) {
			return array();
		}
		$ids = array();
		foreach ( $cart->get_cart() as $item ) {
			$ids[] = (int) $item['product_id'];
			if ( ! empty( $item['variation_id'] ) ) {
				$ids[] = (int) $item['variation_id'];
			}
		}
		return $ids;
	}

	/** "Has the current customer bought X within N days?" — O(1) after the index is built. */
	private static function bought( $kind, array $ids, $days ) {
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return false;
		}
		$idx    = self::purchase_index( $uid );
		$cutoff = $days > 0 ? time() - $days * DAY_IN_SECONDS : 0;
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( isset( $idx[ $kind ][ $id ] ) && $idx[ $kind ][ $id ] >= $cutoff ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Per-user index: ['p' => [product_id => last_purchase_ts], 'c' => [term_id => last_purchase_ts]].
	 * Built once from paid orders, stored in user meta, rebuilt when an order changes status.
	 */
	public static function purchase_index( $uid ) {
		static $mem = array();
		if ( isset( $mem[ $uid ] ) ) {
			return $mem[ $uid ];
		}
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return $mem[ $uid ] = array( 'p' => array(), 'c' => array() );
		}

		$stored = get_user_meta( $uid, self::PIDX_META, true );
		if ( is_array( $stored ) && isset( $stored['built'] ) && $stored['built'] > time() - DAY_IN_SECONDS ) {
			return $mem[ $uid ] = $stored;
		}

		$idx    = array( 'p' => array(), 'c' => array(), 'built' => time() );
		$orders = wc_get_orders(
			array(
				'customer_id' => $uid,
				'status'      => apply_filters( 'visigate_paid_statuses', array( 'completed', 'processing' ) ),
				'limit'       => (int) apply_filters( 'visigate_max_orders_indexed', 300 ),
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);
		$cat_cache = array();
		foreach ( $orders as $order ) {
			$ts = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;
			foreach ( $order->get_items() as $item ) {
				$pid = (int) $item->get_product_id();
				$vid = (int) $item->get_variation_id();
				foreach ( array_filter( array( $pid, $vid ) ) as $id ) {
					$idx['p'][ $id ] = max( $idx['p'][ $id ] ?? 0, $ts );
				}
				if ( ! isset( $cat_cache[ $pid ] ) ) {
					$terms = wp_get_post_terms( $pid, 'product_cat', array( 'fields' => 'ids' ) );
					$all   = array();
					foreach ( (array) $terms as $tid ) {
						$all[] = $tid;
						$all   = array_merge( $all, get_ancestors( $tid, 'product_cat', 'taxonomy' ) );
					}
					$cat_cache[ $pid ] = array_unique( array_map( 'intval', $all ) );
				}
				foreach ( $cat_cache[ $pid ] as $tid ) {
					$idx['c'][ $tid ] = max( $idx['c'][ $tid ] ?? 0, $ts );
				}
			}
		}
		update_user_meta( $uid, self::PIDX_META, $idx );
		return $mem[ $uid ] = $idx;
	}

	public static function bust_index( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( $order && $order->get_customer_id() ) {
			delete_user_meta( $order->get_customer_id(), self::PIDX_META );
		}
	}
}
