<?php
defined( 'ABSPATH' ) || exit;

/**
 * Front-end enforcement.
 *
 * Gutenberg: rules ride on every block as attributes (`visigateSet`, `visigateRules`).
 * We short-circuit in `pre_render_block`, so hidden blocks (and all their inner
 * blocks) are never rendered at all. No front-end CSS or JS is ever loaded.
 */
class Visigate_Frontend {

	public static function init() {
		add_filter( 'pre_render_block', array( __CLASS__, 'pre_render' ), 10, 2 );
		add_filter( 'render_block', array( __CLASS__, 'render_block' ), 10, 2 ); // fallback + admin preview
		add_filter( 'register_block_type_args', array( __CLASS__, 'register_attrs' ) );
		add_shortcode( 'visigate', array( __CLASS__, 'shortcode' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 90 );
	}

	/** Make sure server-registered blocks keep our attributes. */
	public static function register_attrs( $args ) {
		$args['attributes']                  = isset( $args['attributes'] ) ? $args['attributes'] : array();
		$args['attributes']['visigateSet']   = array( 'type' => 'number', 'default' => 0 );
		$args['attributes']['visigateRules'] = array( 'type' => 'object' );
		return $args;
	}

	private static function has_rules( $block ) {
		$a = $block['attrs'] ?? array();
		return ! empty( $a['visigateSet'] ) || ! empty( $a['visigateRules'] );
	}

	private static function in_editor_context() {
		if ( is_admin() ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return defined( 'REST_REQUEST' ) && REST_REQUEST && isset( $_GET['context'] ) && 'edit' === $_GET['context'];
	}

	public static function preview_enabled() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return Visigate_Admin::opt( 'admin_preview' ) && isset( $_GET['visigate_preview'] ) && current_user_can( 'manage_options' );
	}

	private static function block_visible( $block ) {
		$a = $block['attrs'] ?? array();
		if ( ! empty( $a['visigateSet'] ) && ! Visigate_Engine::set_visible( (int) $a['visigateSet'] ) ) {
			return false;
		}
		if ( ! empty( $a['visigateRules'] ) && is_array( $a['visigateRules'] ) && ! Visigate_Engine::visible( $a['visigateRules'] ) ) {
			return false;
		}
		return true;
	}

	public static function pre_render( $pre, $block ) {
		if ( null !== $pre || ! self::has_rules( $block ) || self::in_editor_context() || self::preview_enabled() ) {
			return $pre;
		}
		return self::block_visible( $block ) ? $pre : '';
	}

	public static function render_block( $content, $block ) {
		if ( ! self::has_rules( $block ) || self::in_editor_context() ) {
			return $content;
		}
		$visible = self::block_visible( $block );
		if ( self::preview_enabled() ) {
			if ( $visible ) {
				return $content;
			}
			return '<div class="visigate-preview-hidden" title="' . esc_attr__( 'Hidden from this visitor by Visigate', 'visigate' ) . '" style="outline:2px dashed #d63638;outline-offset:2px;opacity:.5">' . $content . '</div>';
		}
		return $visible ? $content : '';
	}

	/** [visigate set="12" logged_in="yes" roles="customer,subscriber" device="mobile" bought="45,46" bought_days="30" hide="no"]…[/visigate] */
	public static function shortcode( $atts, $content = '' ) {
		$a = shortcode_atts(
			array( 'set' => '', 'logged_in' => '', 'roles' => '', 'device' => '', 'bought' => '', 'bought_days' => '0', 'hide' => 'no' ),
			$atts,
			'visigate'
		);

		if ( '' !== $a['set'] && ! Visigate_Engine::set_visible( (int) $a['set'] ) ) {
			return '';
		}

		$csv   = function ( $s ) {
			return array_values( array_filter( array_map( 'trim', explode( ',', (string) $s ) ) ) );
		};
		$conds = array();
		if ( '' !== $a['logged_in'] ) {
			$conds[] = array( 'type' => 'logged_in', 'op' => in_array( strtolower( $a['logged_in'] ), array( 'yes', '1', 'true' ), true ) ? 'is' : 'is_not', 'value' => null );
		}
		if ( $csv( $a['roles'] ) ) {
			$conds[] = array( 'type' => 'user_role', 'op' => 'is_any', 'value' => $csv( $a['roles'] ) );
		}
		if ( $csv( $a['device'] ) ) {
			$conds[] = array( 'type' => 'device', 'op' => 'is_any', 'value' => $csv( $a['device'] ) );
		}
		if ( $csv( $a['bought'] ) ) {
			$conds[] = array( 'type' => 'woo_bought_product', 'op' => 'bought', 'value' => array_map( 'absint', $csv( $a['bought'] ) ), 'days' => (int) $a['bought_days'] );
		}
		if ( $conds ) {
			$rules = array( 'action' => 'yes' === $a['hide'] ? 'hide' : 'show', 'groups' => array( array( 'conditions' => $conds ) ) );
			if ( ! Visigate_Engine::visible( $rules ) ) {
				return '';
			}
		}
		return do_shortcode( $content );
	}

	/** Admin-bar toggle: preview what is being hidden on the current page. */
	public static function admin_bar( $bar ) {
		if ( is_admin() || ! current_user_can( 'manage_options' ) || ! Visigate_Admin::opt( 'admin_preview' ) ) {
			return;
		}
		$on = self::preview_enabled();
		$bar->add_node(
			array(
				'id'    => 'visigate-preview',
				'title' => $on ? __( 'Visigate: hide hidden content', 'visigate' ) : __( 'Visigate: reveal hidden content', 'visigate' ),
				'href'  => $on ? remove_query_arg( 'visigate_preview' ) : add_query_arg( 'visigate_preview', '1' ),
			)
		);
	}
}
