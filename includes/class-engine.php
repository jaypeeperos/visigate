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
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'visigate_dynamic_page' );
	}

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

			case 'device':
				$hit = in_array( self::device(), (array) $v, true );
				return ( 'is_any' === $op ) ? $hit : ! $hit;

			case 'weekday':
				$hit = in_array( current_datetime()->format( 'N' ), array_map( 'strval', (array) $v ), true );
				return ( 'is_any' === $op ) ? $hit : ! $hit;

			case 'time_of_day':
				$now = current_datetime();
				$cur = (int) $now->format( 'G' ) * 60 + (int) $now->format( 'i' );
				$f   = self::minutes( $v['from'] );
				$t   = self::minutes( $v['to'] );
				$in  = ( $f <= $t ) ? ( $cur >= $f && $cur < $t ) : ( $cur >= $f || $cur < $t ); // overnight ranges
				return ( 'between' === $op ) ? $in : ! $in;

			case 'date_range':
				$now = current_datetime()->getTimestamp();
				$f   = ! empty( $v['from'] ) ? date_create_immutable( $v['from'], wp_timezone() ) : null;
				$t   = ! empty( $v['to'] ) ? date_create_immutable( $v['to'], wp_timezone() ) : null;
				$in  = ( ! $f || $now >= $f->getTimestamp() ) && ( ! $t || $now <= $t->getTimestamp() );
				return ( 'within' === $op ) ? $in : ! $in;
		}

		// Add-on conditions. Unknown / inactive conditions evaluate to false (fail closed for "show" rules).
		return (bool) apply_filters( 'visigate_check_condition', false, $c );
	}

	private static function minutes( $hhmm ) {
		$p = explode( ':', (string) $hhmm );
		return ( (int) $p[0] ) * 60 + (int) ( $p[1] ?? 0 );
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
}
