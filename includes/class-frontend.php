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
		return Visigate_Admin::opt( 'admin_preview' ) && isset( $_GET['visigate_preview'] ) && current_user_can( visigate_capability() );
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
		return self::block_visible( $block ) ? $pre : self::hidden_block_output( $block );
	}

	/**
	 * What a hidden block renders as. Empty by default; add-ons can return replacement ("fallback") HTML.
	 *
	 * @param array $block Parsed block.
	 */
	private static function hidden_block_output( $block ) {
		$out = apply_filters( 'visigate_hidden_block_output', '', $block );
		return is_string( $out ) ? $out : '';
	}

	/**
	 * What a hidden [visigate] shortcode renders as. Empty by default.
	 *
	 * @param array $atts Raw shortcode attributes (including any unknown ones, e.g. from add-ons).
	 */
	private static function hidden_shortcode_output( $atts ) {
		$out = apply_filters( 'visigate_hidden_shortcode_output', '', (array) $atts );
		return is_string( $out ) ? $out : '';
	}

	/**
	 * Admin preview: show hidden content faded and outlined in red instead of removing it.
	 *
	 * @param string $html Content that is hidden from the current visitor.
	 */
	private static function preview_wrap( $html ) {
		return '<div class="visigate-preview-hidden" title="' . esc_attr__( 'Hidden from this visitor by Visigate', 'visigate' ) . '" style="outline:2px dashed #d63638;outline-offset:2px;opacity:.5">' . $html . '</div>';
	}

	/**
	 * Output for a [visigate] shortcode whose content is hidden: the fallback, or the
	 * revealed content while an administrator is using admin preview.
	 *
	 * @param array  $atts    Raw shortcode attributes.
	 * @param string $content Shortcode content.
	 */
	private static function hidden_shortcode( $atts, $content ) {
		if ( self::preview_enabled() ) {
			return self::preview_wrap( do_shortcode( (string) $content ) );
		}
		return self::hidden_shortcode_output( $atts );
	}

	public static function render_block( $content, $block ) {
		if ( ! self::has_rules( $block ) || self::in_editor_context() ) {
			return $content;
		}
		$visible = self::block_visible( $block );
		if ( self::preview_enabled() ) {
			return $visible ? $content : self::preview_wrap( $content );
		}
		return $visible ? $content : self::hidden_block_output( $block );
	}

	/** [visigate set="12" logged_in="yes" roles="customer,subscriber" device="mobile" bought="45,46" bought_days="30" hide="no"]…[/visigate] */
	public static function shortcode( $atts, $content = '' ) {
		$a = shortcode_atts(
			array( 'set' => '', 'logged_in' => '', 'roles' => '', 'device' => '', 'bought' => '', 'bought_days' => '0', 'hide' => 'no' ),
			$atts,
			'visigate'
		);

		if ( '' !== $a['set'] && ! Visigate_Engine::set_visible( (int) $a['set'] ) ) {
			return self::hidden_shortcode( $atts, $content );
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
				return self::hidden_shortcode( $atts, $content );
			}
		}
		return do_shortcode( $content );
	}

	/** Admin-bar toggle: preview what is being hidden on the current page. */
	public static function admin_bar( $bar ) {
		if ( is_admin() || ! current_user_can( visigate_capability() ) || ! Visigate_Admin::opt( 'admin_preview' ) ) {
			return;
		}
		$on = self::preview_enabled();
		$bar->add_node(
			array(
				'id'    => 'visigate-preview',
				/* translators: %s: plugin name */
				'title' => sprintf( $on ? __( '%s: hide hidden content', 'visigate' ) : __( '%s: reveal hidden content', 'visigate' ), visigate_brand() ),
				'href'  => $on ? remove_query_arg( 'visigate_preview' ) : add_query_arg( 'visigate_preview', '1' ),
			)
		);
	}
}
