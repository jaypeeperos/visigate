<?php
defined( 'ABSPATH' ) || exit;

/**
 * Evaluates rules (free conditions). Everything is server-side and memoised per request.
 * Add-ons (e.g. Visigate Pro) register conditions with `visigate_conditions` and evaluate
 * them with the `visigate_check_condition` filter.
 */
class Visigate_Engine {

	private static $memo = array();

	public static function init() {
		// Reserved for future core hooks.
	}

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
		$matched = false;
		$dynamic = false;
		$groups  = array();

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

		// OR of ANDs, short-circuiting.
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
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard constant read by page-cache plugins.
		}
		do_action( 'visigate_dynamic_page' );
	}

	public static function check( $c ) {
		$type = $c['type'];
		$op   = $c['op'] ?? '';
		$v    = $c['value'] ?? null;

		switch ( $type ) {
			case 'logged_in':
				$in = Visigate_View_As::logged_in();
				return ( 'is' === $op ) ? $in : ! $in;

			case 'user_role':
				$hit = (bool) array_intersect( Visigate_View_As::roles(), array_map( 'strval', (array) $v ) );
				return ( 'is_any' === $op ) ? $hit : ! $hit;

			case 'specific_user':
				$user_id = Visigate_View_As::user_id();
				$hit     = $user_id > 0 && in_array( (string) $user_id, array_map( 'strval', (array) $v ), true );
				return ( 'is_any' === $op ) ? $hit : ! $hit;

			case 'capability':
				$has = Visigate_View_As::logged_in() && Visigate_View_As::can( sanitize_key( (string) $v ) );
				return ( 'has' === $op ) ? $has : ! $has;

			case 'user_registered':
				if ( ! Visigate_View_As::logged_in() || ! is_user_logged_in() ) {
					return false;
				}
				$age = ( self::now()->getTimestamp() - strtotime( wp_get_current_user()->user_registered . ' UTC' ) ) / DAY_IN_SECONDS;
				return ( 'older' === $op ) ? $age > (float) $v : $age < (float) $v;

			case 'url_param':
				// Block attributes are not sanitized before rendering, so never trust the key's type.
				$key = self::kv_part( $v, 'key' );
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$raw = '' !== $key && isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : null;
				return self::string_check( $raw, $op, self::kv_part( $v, 'value' ) );

			case 'cookie':
				$key = self::kv_part( $v, 'key' );
				$raw = '' !== $key && class_exists( 'Visigate_Pro_View_As' ) ? Visigate_Pro_View_As::cookie( $key ) : null;
				if ( null === $raw ) {
					$raw = '' !== $key && isset( $_COOKIE[ $key ] ) && is_scalar( $_COOKIE[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_COOKIE[ $key ] ) ) : null;
				}
				return self::string_check( $raw, $op, self::kv_part( $v, 'value' ) );

			case 'referrer':
				if ( class_exists( 'Visigate_Pro_View_As' ) ) {
					$sim = Visigate_Pro_View_As::check( $c );
					if ( null !== $sim ) {
						return $sim;
					}
				}
				$ref = isset( $_SERVER['HTTP_REFERER'] ) && is_string( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
				$needle = is_scalar( $v ) ? (string) $v : '';
				$hit    = '' !== $ref && '' !== $needle && false !== stripos( $ref, $needle );
				return ( 'contains' === $op ) ? $hit : ! $hit;

			case 'device':
				$hit = in_array( self::device(), (array) $v, true );
				return ( 'is_any' === $op ) ? $hit : ! $hit;

			// Off singular pages (home, archives, search) no listed post is being viewed, so
			// "is any of" is false and "is none of" is true.
			case 'specific_post':
				$post_id = is_singular() ? get_queried_object_id() : 0;
				$hit     = $post_id > 0 && in_array( (string) $post_id, array_map( 'strval', (array) $v ), true );
				return ( 'is_any' === $op ) ? $hit : ! $hit;

			case 'post_type':
				$post_types = (array) $v;
				$hit        = $post_types && ( is_singular( $post_types ) || is_post_type_archive( $post_types ) );
				return ( 'is_any' === $op ) ? $hit : ! $hit;

			case 'page_view':
				return ( 'singular' === $op && is_singular() ) || ( 'archive' === $op && is_archive() );

			case 'weekday':
				$hit = in_array( self::now()->format( 'N' ), array_map( 'strval', (array) $v ), true );
				return ( 'is_any' === $op ) ? $hit : ! $hit;

			case 'time_of_day':
				$now = self::now();
				$cur = (int) $now->format( 'G' ) * 60 + (int) $now->format( 'i' );
				$f   = self::minutes( $v['from'] );
				$t   = self::minutes( $v['to'] );
				$in  = ( $f <= $t ) ? ( $cur >= $f && $cur < $t ) : ( $cur >= $f || $cur < $t ); // overnight ranges
				return ( 'between' === $op ) ? $in : ! $in;

			case 'date_range':
				$now = self::now()->getTimestamp();
				$f   = ! empty( $v['from'] ) ? date_create_immutable( $v['from'], wp_timezone() ) : null;
				$t   = ! empty( $v['to'] ) ? date_create_immutable( $v['to'], wp_timezone() ) : null;
				if ( $t && ! empty( $v['to'] ) && 10 === strlen( (string) $v['to'] ) ) {
					$t = $t->setTime( 23, 59, 59 ); // A date without a time includes that whole day.
				}
				$in  = ( ! $f || $now >= $f->getTimestamp() ) && ( ! $t || $now <= $t->getTimestamp() );
				return ( 'within' === $op ) ? $in : ! $in;
		}

		// Add-on conditions. Unknown / inactive conditions evaluate to false (fail closed for "show" rules).
		return (bool) apply_filters( 'visigate_check_condition', false, $c );
	}

	/** One part ("key" or "value") of a key/value condition as a string; '' when missing or malformed. */
	private static function kv_part( $v, $part ) {
		return is_array( $v ) && isset( $v[ $part ] ) && is_scalar( $v[ $part ] ) ? (string) $v[ $part ] : '';
	}

	public static function string_check( $actual, $op, $expected ) {
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

	/** Current site time, or the time being previewed with View as. */
	public static function now() {
		$sim = Visigate_View_As::now();
		return $sim ? $sim : current_datetime();
	}

	private static function minutes( $hhmm ) {
		$p = explode( ':', (string) $hhmm );
		return ( (int) $p[0] ) * 60 + (int) ( $p[1] ?? 0 );
	}

	public static function device() {
		$sim = Visigate_View_As::device();
		if ( null !== $sim ) {
			return $sim;
		}
		static $d = null;
		if ( null !== $d ) {
			return $d;
		}
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( preg_match( '/ipad|tablet|kindle|silk|playbook|(android(?!.*mobile))/i', $ua ) ) {
			$d = 'tablet';
		} elseif ( wp_is_mobile() ) {
			$d = 'mobile';
		} else {
			$d = 'desktop';
		}
		return $d = apply_filters( 'visigate_device', $d );
	}
}
