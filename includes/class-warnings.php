<?php
defined( 'ABSPATH' ) || exit;

/**
 * Rule warnings.
 *
 * Most checks run in the rule builder (assets/js/builder.js) as you edit: contradictory conditions,
 * incomplete conditions, empty groups and date ranges that have already ended. This class adds the
 * parts that need the server:
 *  - a REST endpoint that reports selected posts, users, terms, products or rule sets that no longer exist
 *  - a "Warnings" note in the Rule Sets list for rules that can no longer match
 */
class Visigate_Warnings {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			'visigate/v1',
			'/validate',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( visigate_capability() );
				},
				'args'                => array(
					'items' => array( 'type' => 'object', 'required' => true ),
				),
				'callback'            => array( __CLASS__, 'rest_validate' ),
			)
		);
	}

	/** { items: { source: [ids] } } => { missing: { source: [ids] } } */
	public static function rest_validate( WP_REST_Request $req ) {
		$items   = $req->get_param( 'items' );
		$missing = array();
		$checked = 0;
		foreach ( is_array( $items ) ? $items : array() as $source => $ids ) {
			$source = sanitize_key( (string) $source );
			$ids    = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
			foreach ( $ids as $id ) {
				if ( ++$checked > 300 ) {
					break 2;
				}
				if ( ! self::target_exists( $source, $id ) ) {
					$missing[ $source ][] = $id;
				}
			}
		}
		return rest_ensure_response( array( 'missing' => (object) $missing ) );
	}

	/**
	 * Does the item an async condition points at still exist? Unknown sources count as existing.
	 *
	 * @param string $source Search source of the condition (users, posts, product, product_cat…).
	 * @param int    $id     Selected ID.
	 */
	public static function target_exists( $source, $id ) {
		$custom = apply_filters( 'visigate_target_exists', null, $source, $id );
		if ( null !== $custom ) {
			return (bool) $custom;
		}
		switch ( $source ) {
			case 'users':
				return (bool) get_userdata( $id );
			case 'posts':
			case 'product':
				$post = get_post( $id );
				return $post && ! in_array( $post->post_status, array( 'trash', 'auto-draft' ), true );
			case 'rule_sets':
				$post = get_post( $id );
				return $post && Visigate_Ruleset::CPT === $post->post_type && 'publish' === $post->post_status;
			case 'category_tag_terms':
			case 'product_cat':
			case 'custom_terms':
			case 'public_terms':
				$term = get_term( $id );
				return $term instanceof WP_Term;
		}
		return true;
	}

	/**
	 * Problems that mean saved rules can no longer behave as intended (shown in the Rule Sets list).
	 *
	 * @param array $rules Saved rules.
	 * @return string[]
	 */
	public static function server_flags( $rules ) {
		$flags = array();
		if ( ! is_array( $rules ) || ! empty( $rules['always_hide'] ) || empty( $rules['groups'] ) ) {
			return $flags;
		}
		$all     = Visigate_Conditions::all();
		$now     = current_datetime()->getTimestamp();
		$ended   = false;
		$missing = array();
		foreach ( (array) $rules['groups'] as $g ) {
			foreach ( (array) ( $g['conditions'] ?? array() ) as $c ) {
				$type = is_array( $c ) ? (string) ( $c['type'] ?? '' ) : '';
				if ( '' === $type ) {
					continue;
				}
				if ( ! isset( $all[ $type ] ) ) {
					$missing[ $type ] = true;
					continue;
				}
				if ( 'date_range' === $type && 'within' === ( $c['op'] ?? 'within' ) && ! empty( $c['value']['to'] ) && is_string( $c['value']['to'] ) ) {
					$to = date_create_immutable( $c['value']['to'], wp_timezone() );
					if ( $to && 10 === strlen( $c['value']['to'] ) ) {
						$to = $to->setTime( 23, 59, 59 );
					}
					if ( $to && $to->getTimestamp() < $now ) {
						$ended = true;
					}
				}
			}
		}
		if ( $ended ) {
			$flags[] = __( 'A date range has ended', 'visigate' );
		}
		if ( $missing ) {
			$flags[] = __( 'Uses conditions from an inactive add-on (they count as not met)', 'visigate' );
		}
		return $flags;
	}
}
