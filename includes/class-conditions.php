<?php
defined( 'ABSPATH' ) || exit;

/**
 * Registry of available conditions + sanitization of rule data.
 *
 * Rule data model (stored as JSON-like arrays):
 *
 *   {
 *     "action": "show" | "hide",
 *     "groups": [                       // groups are OR'd
 *        { "conditions": [              // conditions inside a group are AND'd
 *            { "type": "user_role", "op": "is_any", "value": ["customer"] },
 *            { "type": "woo_bought_product", "op": "bought", "value": [12], "days": 30 }
 *        ] }
 *     ]
 *   }
 *
 * Developers can add conditions with the `visigate_conditions` filter and
 * evaluate them with the `visigate_check_condition` filter.
 */
class Visigate_Conditions {

	private static $all = null;

	public static function groups() {
		return array(
			'user'    => __( 'User', 'visigate' ),
			'visitor' => __( 'Visitor', 'visigate' ),
			'time'    => __( 'Time', 'visigate' ),
			'woo'     => __( 'WooCommerce', 'visigate' ),
		);
	}

	/** Option lists used by token fields. */
	public static function options() {
		$roles = array();
		foreach ( wp_roles()->roles as $key => $role ) {
			$roles[ $key ] = translate_user_role( $role['name'] );
		}

		return apply_filters( 'visigate_condition_options', array(
			'roles'    => $roles,
			'devices'  => array(
				'desktop' => __( 'Desktop', 'visigate' ),
				'tablet'  => __( 'Tablet', 'visigate' ),
				'mobile'  => __( 'Mobile', 'visigate' ),
			),
			'weekdays' => array(
				'1' => __( 'Monday', 'visigate' ),
				'2' => __( 'Tuesday', 'visigate' ),
				'3' => __( 'Wednesday', 'visigate' ),
				'4' => __( 'Thursday', 'visigate' ),
				'5' => __( 'Friday', 'visigate' ),
				'6' => __( 'Saturday', 'visigate' ),
				'7' => __( 'Sunday', 'visigate' ),
			),
		) );
	}

	/** Label-only upsell entries shown in the builder when Visigate Pro is not installed. */
	public static function teasers() {
		$t = array(
			array( 'label' => __( 'Country', 'visigate' ), 'group' => 'visitor' ),
			array( 'label' => __( 'URL parameter', 'visigate' ), 'group' => 'visitor' ),
			array( 'label' => __( 'Cookie', 'visigate' ), 'group' => 'visitor' ),
			array( 'label' => __( 'Referrer', 'visigate' ), 'group' => 'visitor' ),
			array( 'label' => __( 'User meta', 'visigate' ), 'group' => 'user' ),
		);
		if ( class_exists( 'WooCommerce' ) ) {
			$woo = array(
				__( 'Purchased product (last N days)', 'visigate' ),
				__( 'Purchased from category', 'visigate' ),
				__( 'Lifetime orders', 'visigate' ),
				__( 'Lifetime spend', 'visigate' ),
				__( 'Cart contents', 'visigate' ),
				__( 'Cart subtotal', 'visigate' ),
				__( 'Active subscription', 'visigate' ),
				__( 'Coupon used', 'visigate' ),
				__( 'Shipping country', 'visigate' ),
				__( 'Has left a review', 'visigate' ),
				__( 'Days since last order', 'visigate' ),
			);
			foreach ( $woo as $l ) {
				$t[] = array( 'label' => $l, 'group' => 'woo' );
			}
		}
		return $t;
	}

	public static function all() {
		if ( null !== self::$all ) {
			return self::$all;
		}

		$in = array(
			'is_any'  => __( 'is any of', 'visigate' ),
			'is_none' => __( 'is none of', 'visigate' ),
		);

		$defs = array(
			// ---- User -------------------------------------------------------
			'logged_in'        => array(
				'label' => __( 'Login status', 'visigate' ), 'group' => 'user', 'input' => 'none',
				'ops'   => array( 'is' => __( 'Logged in', 'visigate' ), 'is_not' => __( 'Logged out (visitor)', 'visigate' ) ),
			),
			'user_role'        => array(
				'label' => __( 'User role', 'visigate' ), 'group' => 'user', 'input' => 'tokens', 'options' => 'roles', 'ops' => $in,
			),
			'capability'       => array(
				'label' => __( 'User capability', 'visigate' ), 'group' => 'user', 'input' => 'text',
				'ops'   => array( 'has' => __( 'has', 'visigate' ), 'has_not' => __( 'does not have', 'visigate' ) ),
				'placeholder' => 'edit_posts',
			),
			'user_registered'  => array(
				'label' => __( 'Account age (days)', 'visigate' ), 'group' => 'user', 'input' => 'number',
				'ops'   => array( 'older' => __( 'is more than', 'visigate' ), 'newer' => __( 'is less than', 'visigate' ) ),
			),

			// ---- Visitor ----------------------------------------------------
			'device'           => array(
				'label' => __( 'Device type', 'visigate' ), 'group' => 'visitor', 'input' => 'tokens', 'options' => 'devices', 'ops' => $in, 'dynamic' => true,
			),

			// ---- Time -------------------------------------------------------
			'weekday'          => array(
				'label' => __( 'Day of week', 'visigate' ), 'group' => 'time', 'input' => 'tokens', 'options' => 'weekdays', 'ops' => $in, 'dynamic' => true,
			),
			'time_of_day'      => array(
				'label' => __( 'Time of day', 'visigate' ), 'group' => 'time', 'input' => 'time_range', 'dynamic' => true,
				'ops'   => array( 'between' => __( 'is between', 'visigate' ), 'not_between' => __( 'is not between', 'visigate' ) ),
			),
			'date_range'       => array(
				'label' => __( 'Date range', 'visigate' ), 'group' => 'time', 'input' => 'date_range', 'dynamic' => true,
				'ops'   => array( 'within' => __( 'is within', 'visigate' ), 'outside' => __( 'is outside', 'visigate' ) ),
			),

		);

		$defs = apply_filters( 'visigate_conditions', $defs );

		foreach ( $defs as $key => &$d ) {
			$d = wp_parse_args(
				$d,
				array(
					'label' => $key, 'group' => 'user', 'input' => 'none', 'pro' => false, 'dynamic' => false,
					'days'  => false, 'options' => '', 'source' => '', 'no_value' => array(), 'ops' => array( 'is' => '' ),
					'locked' => false, 'lock_label' => '',
				)
			);
			$d['default_op'] = key( $d['ops'] );
		}
		unset( $d );

		self::$all = $defs;
		return self::$all;
	}

	/** Is a condition fully configured? Incomplete conditions are ignored, not failed. */
	public static function is_complete( $c ) {
		$all = self::all();
		if ( ! is_array( $c ) || empty( $c['type'] ) ) {
			return false;
		}
		if ( ! isset( $all[ $c['type'] ] ) ) {
			// Unknown type (e.g. a Pro condition while Visigate Pro is inactive/removed).
			// Count it as configured so the engine evaluates it to "false" instead of ignoring it,
			// which means "show only when…" rules fail closed rather than leaking content.
			return true;
		}
		$s = $all[ $c['type'] ];
		$v = $c['value'] ?? null;
		switch ( $s['input'] ) {
			case 'none':
				return true;
			case 'tokens':
			case 'async':
				return ! empty( $v );
			case 'number':
			case 'text':
				return '' !== (string) $v && null !== $v;
			case 'kv':
				if ( empty( $v['key'] ) ) {
					return false;
				}
				return in_array( $c['op'] ?? '', $s['no_value'], true ) || '' !== (string) ( $v['value'] ?? '' );
			case 'time_range':
				return ! empty( $v['from'] ) && ! empty( $v['to'] );
			case 'date_range':
				return ! empty( $v['from'] ) || ! empty( $v['to'] );
		}
		return true;
	}

	/** Whitelist-sanitize rules coming from the builder. */
	public static function sanitize( $rules ) {
		$out = array( 'action' => 'show', 'groups' => array() );
		if ( ! is_array( $rules ) ) {
			return $out;
		}
		$out['action'] = ( ( $rules['action'] ?? '' ) === 'hide' ) ? 'hide' : 'show';
		$all           = self::all();
		$opts          = self::options();

		foreach ( (array) ( $rules['groups'] ?? array() ) as $g ) {
			$conds = array();
			foreach ( (array) ( $g['conditions'] ?? array() ) as $c ) {
				$t = $c['type'] ?? '';
				if ( ! is_string( $t ) || '' === $t ) {
					continue;
				}
				if ( ! isset( $all[ $t ] ) ) {
					// Keep rules from add-ons that are currently inactive so saving never destroys them.
					$conds[] = self::sanitize_deep( $c );
					continue;
				}
				$s  = $all[ $t ];
				$op = $c['op'] ?? $s['default_op'];
				if ( ! isset( $s['ops'][ $op ] ) ) {
					$op = $s['default_op'];
				}
				$n = array(
					'type'  => $t,
					'op'    => $op,
					'value' => self::sanitize_value( $s, $c['value'] ?? null, $opts ),
				);
				if ( $s['days'] ) {
					$n['days'] = max( 0, (int) ( $c['days'] ?? 0 ) );
				}
				if ( ! empty( $c['labels'] ) && is_array( $c['labels'] ) ) {
					$n['labels'] = array();
					foreach ( $c['labels'] as $id => $label ) {
						$n['labels'][ (int) $id ] = sanitize_text_field( $label );
					}
				}
				$conds[] = $n;
			}
			if ( $conds ) {
				$out['groups'][] = array( 'conditions' => $conds );
			}
		}
		return $out;
	}

	private static function sanitize_deep( $v ) {
		if ( is_array( $v ) ) {
			$out = array();
			foreach ( $v as $k => $x ) {
				$out[ is_int( $k ) ? $k : sanitize_key( $k ) ] = self::sanitize_deep( $x );
			}
			return $out;
		}
		return is_scalar( $v ) ? sanitize_text_field( (string) $v ) : null;
	}

	private static function sanitize_value( $s, $v, $opts ) {
		switch ( $s['input'] ) {
			case 'tokens':
				$v = array_values( array_unique( array_map( 'sanitize_text_field', (array) $v ) ) );
				if ( $s['options'] && isset( $opts[ $s['options'] ] ) ) {
					$v = array_values( array_intersect( $v, array_map( 'strval', array_keys( $opts[ $s['options'] ] ) ) ) );
				}
				return $v;
			case 'async':
				return array_values( array_unique( array_filter( array_map( 'absint', (array) $v ) ) ) );
			case 'number':
				return is_numeric( $v ) ? $v + 0 : '';
			case 'text':
				return sanitize_text_field( (string) $v );
			case 'kv':
				return array(
					'key'   => sanitize_text_field( (string) ( $v['key'] ?? '' ) ),
					'value' => sanitize_text_field( (string) ( $v['value'] ?? '' ) ),
				);
			case 'time_range':
				$f = (string) ( $v['from'] ?? '' );
				$t = (string) ( $v['to'] ?? '' );
				return array(
					'from' => preg_match( '/^\d{2}:\d{2}$/', $f ) ? $f : '',
					'to'   => preg_match( '/^\d{2}:\d{2}$/', $t ) ? $t : '',
				);
			case 'date_range':
				$re = '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2})?$/';
				$f  = (string) ( $v['from'] ?? '' );
				$t  = (string) ( $v['to'] ?? '' );
				return array(
					'from' => preg_match( $re, $f ) ? $f : '',
					'to'   => preg_match( $re, $t ) ? $t : '',
				);
		}
		return null;
	}
}
